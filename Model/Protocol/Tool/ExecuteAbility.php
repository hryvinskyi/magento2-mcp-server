<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Api\AbilityInvokerInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Model\Confirmation\ConfirmationStoreInterface;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Tool\Outcome\OutcomeAnswerInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * `execute_ability`: runs one ability through the invoker, as the caller, for the client of the session
 *
 * The caller comes from the transport and the client from the session the caller opened. The invoker's outcome is
 * answered by the strategy of the di.xml pool `outcomeAnswers` keyed by that outcome: a result or a tool error the
 * model can act on, or a JSON-RPC error for a denial or a missing confirmation. A name that is no ability, and
 * arguments that are not a JSON object, are tool errors the model can correct.
 *
 * A call is confirmed only by a `confirmationToken` the store handed out for exactly this call (see
 * ConfirmationStoreInterface): the token is spent before the ability runs, and one that confirms nothing leaves the
 * call unconfirmed, so a call to an ability that needs a confirmation is answered with a fresh token. A client cannot
 * confirm a call by declaring it confirmed: a call that still sends the former `confirm` flag is a tool error that
 * explains the token.
 */
class ExecuteAbility implements ToolInterface
{
    public const NAME = 'execute_ability';

    private const ARGUMENT_TOKEN = 'confirmationToken';
    private const RETIRED_ARGUMENT_CONFIRM = 'confirm';

    /**
     * @param AbilityInvokerInterface $invoker
     * @param InvocationInterfaceFactory $invocationFactory
     * @param ClientInterfaceFactory $clientFactory
     * @param ArgumentReader $arguments
     * @param ToolResults $toolResults
     * @param ConfirmationStoreInterface $confirmations
     * @param array<string,OutcomeAnswerInterface> $outcomeAnswers Answers keyed by the invocation outcome's value
     */
    public function __construct(
        private readonly AbilityInvokerInterface $invoker,
        private readonly InvocationInterfaceFactory $invocationFactory,
        private readonly ClientInterfaceFactory $clientFactory,
        private readonly ArgumentReader $arguments,
        private readonly ToolResults $toolResults,
        private readonly ConfirmationStoreInterface $confirmations,
        private readonly array $outcomeAnswers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Run one ability of this Magento store with its arguments, as your account. The arguments follow the '
            . 'input schema get_ability_info gives. The store may deny the call (its deny-list, read-only mode or '
            . 'your role\'s ACL), or ask for a confirmation: then ask the user, and call again with the same name '
            . 'and arguments plus the confirmationToken the error carries. A token confirms that one call only, once, '
            . 'within ten minutes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The ability name, as discover_abilities lists it.',
                ],
                'arguments' => [
                    'type' => 'object',
                    'default' => (object) [],
                    'description' => 'The ability\'s arguments, keyed by name, as its input schema describes them.',
                ],
                self::ARGUMENT_TOKEN => [
                    'type' => 'string',
                    'description' => 'The token of the error that asked a confirmation for this exact call, sent '
                        . 'once the user confirmed it.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        return [
            'title' => 'Execute ability',
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function call(array $arguments, CallContext $context): array
    {
        if (array_key_exists(self::RETIRED_ARGUMENT_CONFIRM, $arguments)) {
            throw new InvalidToolArgumentException(__(
                'The argument "confirm" is not taken. When a call needs a confirmation, its error carries a '
                . 'confirmationToken: ask the user, then call again with the same name and arguments plus that '
                . '"confirmationToken".'
            )->render());
        }

        $name = $this->arguments->requiredString($arguments, 'name');
        $abilityArguments = $this->arguments->object($arguments, 'arguments');
        $token = $this->arguments->optionalString($arguments, self::ARGUMENT_TOKEN);
        $session = $context->getSession();
        $sessionId = $session !== null ? $session->getId() : '';

        $confirmed = $token !== null && $this->confirmations->consume(
            $token,
            $sessionId,
            $context->getCaller(),
            $name,
            $abilityArguments
        );

        $invocation = $this->invocationFactory->create([
            'abilityName' => $name,
            'caller' => $context->getCaller(),
            'client' => $session !== null ? $session->getClient() : $this->clientFactory->create(),
            'transport' => $context->getTransport()->getTransport(),
            'sessionId' => $sessionId,
            'confirmed' => $confirmed,
        ])->withArguments($abilityArguments);

        try {
            $result = $this->invoker->invoke($invocation);
        } catch (NoSuchEntityException) {
            return $this->toolResults->unknownAbility($name);
        }

        $outcome = $result->getOutcome()->value;
        $answer = $this->outcomeAnswers[$outcome] ?? throw new \LogicException(
            sprintf('No answer is registered for the invocation outcome "%s".', $outcome)
        );

        return $answer->answer($invocation, $result);
    }
}
