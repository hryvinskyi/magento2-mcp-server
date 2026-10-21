<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\AbilityInvokerInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterfaceFactory;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Api\InvocationGuardInterface;
use Hryvinskyi\McpServer\Api\InvocationListenerInterface;
use Hryvinskyi\McpServer\Model\Catalog\AbilitySourceResolverInterface;
use Hryvinskyi\McpServer\Model\Clock\ClockInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\ErrorProcessor;
use Psr\Log\LoggerInterface;

/**
 * Runs one invocation through its checks, in an order that never changes:
 *
 * - The ability is resolved by name first. A name no source offers throws NoSuchEntityException, and no listener
 *   hears of the call.
 * - Every listener in the di.xml pool `listeners` is told the invocation started.
 * - The exposure policy is asked. An ability it does not let through ends as denied with the reason the policy
 *   gives: `deny_list` for the deny-list, `read_only_mode` for an ability blocked by read-only mode,
 *   `anonymous_ability` for one anyone may call while such abilities are not exposed, or another rule's own code.
 * - The caller's ACL is asked of the source. A caller whose role lacks the ability's resources is denied with
 *   reason `acl`.
 * - An ability that needs a confirmation ends as needs_confirmation unless the invocation is confirmed.
 * - Every guard in the di.xml pool `guards` is asked about the arguments. The first refusal ends the invocation as
 *   denied with reason `argument_guard` and the guard's message.
 * - Only then is the source asked to run the ability, so a refused call changes nothing. A LocalizedException ends
 *   as failed with its own message, which is meant for the client. Any other exception ends as failed with the message
 *   Magento's Web API would show for it, and an \Error with a generic one; both are logged, and neither leaks a trace.
 * - Every listener is told how the invocation ended, whatever the outcome.
 *
 * A listener that throws is logged and skipped: it never changes the outcome, and the next listener is still called.
 * A check that throws ends the invocation as failed, as a throwing ability does, so the listeners hear of it too.
 *
 * The duration covers the checks and the call, measured on the injected monotonic clock; the listeners' own time is
 * not part of it.
 */
class AbilityInvoker implements AbilityInvokerInterface
{
    /**
     * @param AbilityCatalogInterface $catalog
     * @param AbilitySourceResolverInterface $sourceResolver
     * @param ExposurePolicyInterface $exposurePolicy
     * @param InvocationResultInterfaceFactory $resultFactory
     * @param ClockInterface $clock
     * @param ErrorProcessor $errorProcessor
     * @param LoggerInterface $logger
     * @param InvocationListenerInterface[] $listeners
     * @param InvocationGuardInterface[] $guards
     */
    public function __construct(
        private readonly AbilityCatalogInterface $catalog,
        private readonly AbilitySourceResolverInterface $sourceResolver,
        private readonly ExposurePolicyInterface $exposurePolicy,
        private readonly InvocationResultInterfaceFactory $resultFactory,
        private readonly ClockInterface $clock,
        private readonly ErrorProcessor $errorProcessor,
        private readonly LoggerInterface $logger,
        private readonly array $listeners = [],
        private readonly array $guards = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function invoke(InvocationInterface $invocation): InvocationResultInterface
    {
        $ability = $this->catalog->get($invocation->getAbilityName());

        $this->notify(
            'started',
            static fn (InvocationListenerInterface $listener) => $listener->started($invocation)
        );

        $result = $this->run($ability, $invocation, $this->clock->nowMs());

        $this->notify(
            'finished',
            static fn (InvocationListenerInterface $listener) => $listener->finished($invocation, $result)
        );

        return $result;
    }

    /**
     * Check the invocation and call the ability, turning anything thrown into a failed result
     *
     * @param AbilityInterface $ability
     * @param InvocationInterface $invocation
     * @param float $startedAt
     * @return InvocationResultInterface
     */
    private function run(
        AbilityInterface $ability,
        InvocationInterface $invocation,
        float $startedAt
    ): InvocationResultInterface {
        try {
            return $this->refusal($ability, $invocation, $startedAt)
                ?? $this->call($ability, $invocation, $startedAt);
        } catch (LocalizedException $exception) {
            return $this->failed($ability, $startedAt, $exception->getMessage());
        } catch (\Exception $exception) {
            $this->logFailure($ability, $exception);

            return $this->failed($ability, $startedAt, $this->errorProcessor->maskException($exception)->getMessage());
        } catch (\Throwable $error) {
            $this->logFailure($ability, $error);

            return $this->failed($ability, $startedAt, __('Internal error')->render());
        }
    }

    /**
     * The result of a call the checks refuse, or null when every check lets it through
     *
     * @param AbilityInterface $ability
     * @param InvocationInterface $invocation
     * @param float $startedAt
     * @return InvocationResultInterface|null
     */
    private function refusal(
        AbilityInterface $ability,
        InvocationInterface $invocation,
        float $startedAt
    ): ?InvocationResultInterface {
        $decision = $this->exposurePolicy->decisionOf($ability);
        if (!$decision->getExposure()->allowsCall()) {
            return $this->denied(
                $ability,
                $startedAt,
                $decision->getDenialReason(),
                $this->exposureDenialMessage($ability, $decision)->render()
            );
        }

        if (!$this->catalog->isAllowed($ability)) {
            return $this->denied(
                $ability,
                $startedAt,
                InvocationResultInterface::DENIAL_ACL,
                __(
                    'The ability "%1" needs ACL resources your role lacks: %2.',
                    $ability->getName(),
                    implode(', ', $ability->getAclResources())
                )->render()
            );
        }

        if ($decision->getExposure() === Exposure::NeedsConfirmation && !$invocation->isConfirmed()) {
            return $this->resultFactory->create([
                'outcome' => InvocationOutcome::NeedsConfirmation,
                'durationMs' => $this->elapsedSince($startedAt),
                'ability' => $ability,
                'message' => __('The ability "%1" runs only when the call is confirmed.', $ability->getName())
                    ->render(),
            ]);
        }

        return $this->guardRefusal($ability, $invocation, $startedAt);
    }

    /**
     * The denied result of the first guard that refuses the arguments, or null when every guard lets them through
     *
     * @param AbilityInterface $ability
     * @param InvocationInterface $invocation
     * @param float $startedAt
     * @return InvocationResultInterface|null
     */
    private function guardRefusal(
        AbilityInterface $ability,
        InvocationInterface $invocation,
        float $startedAt
    ): ?InvocationResultInterface {
        foreach ($this->guards as $guard) {
            $refusal = $guard->refusal($ability, $invocation->getArguments());
            if ($refusal === null) {
                continue;
            }

            return $this->denied(
                $ability,
                $startedAt,
                InvocationResultInterface::DENIAL_ARGUMENT_GUARD,
                trim($refusal) !== ''
                    ? $refusal
                    : __('The arguments of this call of the ability "%1" are refused.', $ability->getName())->render()
            );
        }

        return null;
    }

    /**
     * What the client is told about an ability the exposure policy does not let through
     *
     * @param AbilityInterface $ability
     * @param ExposureDecisionInterface $decision
     * @return Phrase
     */
    private function exposureDenialMessage(AbilityInterface $ability, ExposureDecisionInterface $decision): Phrase
    {
        $name = $ability->getName();
        $reason = $decision->getDenialReason();

        return match ($reason) {
            InvocationResultInterface::DENIAL_DENY_LIST => __(
                'The ability "%1" is on the deny-list of this store.',
                $name
            ),
            InvocationResultInterface::DENIAL_READ_ONLY_MODE => __(
                'Read-only mode is on, and the ability "%1" changes data.',
                $name
            ),
            InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY => __(
                'The ability "%1" is open to anonymous callers, and this store does not expose such abilities.',
                $name
            ),
            default => __('The ability "%1" is not exposed to MCP clients (%2).', $name, $reason),
        };
    }

    /**
     * Ask the source that offers the ability to run it
     *
     * @param AbilityInterface $ability
     * @param InvocationInterface $invocation
     * @param float $startedAt
     * @return InvocationResultInterface
     * @throws \Throwable Whatever the source throws
     */
    private function call(
        AbilityInterface $ability,
        InvocationInterface $invocation,
        float $startedAt
    ): InvocationResultInterface {
        $value = $this->sourceResolver->sourceOf($ability)->invoke($ability, $invocation->getArguments());

        return $this->resultFactory->create([
            'outcome' => InvocationOutcome::Succeeded,
            'durationMs' => $this->elapsedSince($startedAt),
            'ability' => $ability,
        ])->withValue($value);
    }

    /**
     * A denied result with the reason and the message that explains it
     *
     * @param AbilityInterface $ability
     * @param float $startedAt
     * @param string $reason
     * @param string $message
     * @return InvocationResultInterface
     */
    private function denied(
        AbilityInterface $ability,
        float $startedAt,
        string $reason,
        string $message
    ): InvocationResultInterface {
        return $this->resultFactory->create([
            'outcome' => InvocationOutcome::Denied,
            'durationMs' => $this->elapsedSince($startedAt),
            'ability' => $ability,
            'message' => $message,
            'denialReason' => $reason,
        ]);
    }

    /**
     * A failed result with the message the client may see
     *
     * @param AbilityInterface $ability
     * @param float $startedAt
     * @param string $message
     * @return InvocationResultInterface
     */
    private function failed(AbilityInterface $ability, float $startedAt, string $message): InvocationResultInterface
    {
        return $this->resultFactory->create([
            'outcome' => InvocationOutcome::Failed,
            'durationMs' => $this->elapsedSince($startedAt),
            'ability' => $ability,
            'message' => trim($message) !== '' ? $message : __('Internal error')->render(),
        ]);
    }

    /**
     * Log what made an ability fail, with its trace
     *
     * @param AbilityInterface $ability
     * @param \Throwable $failure
     * @return void
     */
    private function logFailure(AbilityInterface $ability, \Throwable $failure): void
    {
        $this->logger->error(
            sprintf('MCP ability "%s" failed: %s', $ability->getName(), $failure->getMessage()),
            ['exception' => $failure]
        );
    }

    /**
     * Tell every listener, logging and skipping one that throws
     *
     * @param string $event Name of the listener method, for the log
     * @param \Closure(InvocationListenerInterface):void $tell
     * @return void
     */
    private function notify(string $event, \Closure $tell): void
    {
        foreach ($this->listeners as $code => $listener) {
            try {
                $tell($listener);
            } catch (\Throwable $failure) {
                $this->logger->error(
                    sprintf('MCP invocation listener "%s" failed in %s(): %s', $code, $event, $failure->getMessage()),
                    ['exception' => $failure]
                );
            }
        }
    }

    /**
     * Milliseconds since the reading taken when the checks began
     *
     * @param float $startedAt
     * @return float
     */
    private function elapsedSince(float $startedAt): float
    {
        return max(0.0, $this->clock->nowMs() - $startedAt);
    }
}
