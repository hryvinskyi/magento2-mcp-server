<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterfaceFactory;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Api\InvocationGuardInterface;
use Hryvinskyi\McpServer\Api\InvocationListenerInterface;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Data\Invocation;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedExposurePolicy;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingGuard;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingListener;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Hryvinskyi\McpServer\Test\Unit\Fake\StepClock;
use Hryvinskyi\McpServer\Test\Unit\Fake\ThrowingListener;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every branch of the invoker, in the order of its checks: resolution, exposure, ACL, confirmation, the argument
 * guards, then the call
 */
class AbilityInvokerTest extends TestCase
{
    private const READ = 'catalog.product_repository.get_list';
    private const WRITE = 'catalog.product_repository.save';
    private const MASKED = 'Internal Error. Details are available in Magento log file. Report ID: webapi-1';

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var RecordingListener
     */
    private RecordingListener $listener;

    /**
     * @var FakeAbilitySource|null
     */
    private ?FakeAbilitySource $source = null;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->listener = new RecordingListener();
    }

    public function testAnExposedAllowedAbilityRunsWithTheClientsArguments(): void
    {
        $invoker = $this->invoker(Exposure::Exposed, true, static fn (AbilityInterface $ability, array $arguments) => [
            'ability' => $ability->getName(),
            'arguments' => $arguments,
        ]);
        $arguments = ['searchCriteria' => ['pageSize' => 5], 'instance' => 'not a class to build'];

        $result = $invoker->invoke($this->invocation(self::READ)->withArguments($arguments));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertSame(['ability' => self::READ, 'arguments' => $arguments], $result->getValue());
        self::assertSame(self::READ, $result->getAbility()?->getName());
        self::assertSame('', $result->getMessage());
        self::assertSame('', $result->getDenialReason());
        self::assertSame([[self::READ, $arguments]], $this->source()->invocations);
    }

    public function testTheDurationIsMeasuredOnTheClockFromTheChecksToTheResult(): void
    {
        $result = $this->invoker(Exposure::Exposed, true)->invoke($this->invocation(self::READ));

        self::assertSame(7.5, $result->getDurationMs());
    }

    public function testAnUnknownAbilityThrowsBeforeAnyListenerHearsOfIt(): void
    {
        $invoker = $this->invoker(Exposure::Exposed, true);

        try {
            $invoker->invoke($this->invocation('catalog.nothing.here'));
            self::fail('An unknown ability must throw.');
        } catch (NoSuchEntityException $exception) {
            self::assertStringContainsString('catalog.nothing.here', $exception->getMessage());
        }

        self::assertSame([], $this->listener->events);
        self::assertSame([], $this->source()->invocations);
    }

    /**
     * @return array<string, array{Exposure, bool, bool, string}>
     */
    public static function denials(): array
    {
        return [
            'deny-listed' => [Exposure::Denied, true, false, InvocationResultInterface::DENIAL_DENY_LIST],
            'deny-listed, before the ACL' => [
                Exposure::Denied,
                false,
                true,
                InvocationResultInterface::DENIAL_DENY_LIST,
            ],
            'read-only mode' => [
                Exposure::ReadOnlyBlocked,
                true,
                true,
                InvocationResultInterface::DENIAL_READ_ONLY_MODE,
            ],
            'read-only mode, before the ACL' => [
                Exposure::ReadOnlyBlocked,
                false,
                false,
                InvocationResultInterface::DENIAL_READ_ONLY_MODE,
            ],
            'the ACL of an exposed ability' => [Exposure::Exposed, false, false, InvocationResultInterface::DENIAL_ACL],
            'the ACL, before a missing confirmation' => [
                Exposure::NeedsConfirmation,
                false,
                false,
                InvocationResultInterface::DENIAL_ACL,
            ],
            'the ACL, even when confirmed' => [
                Exposure::NeedsConfirmation,
                false,
                true,
                InvocationResultInterface::DENIAL_ACL,
            ],
        ];
    }

    #[DataProvider('denials')]
    public function testADeniedCallNamesItsReasonAndRunsNothing(
        Exposure $exposure,
        bool $allowed,
        bool $confirmed,
        string $reason
    ): void {
        $result = $this->invoker($exposure, $allowed)->invoke($this->invocation(self::WRITE, $confirmed));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame($reason, $result->getDenialReason());
        self::assertStringContainsString(self::WRITE, $result->getMessage());
        self::assertNull($result->getValue());
        self::assertSame([], $this->source()->invocations);
        self::assertSame(['started', 'finished'], $this->listener->events);
        self::assertSame([$result], $this->listener->results);
    }

    /**
     * @return array<string, array{Exposure, string, string}>
     */
    public static function policyReasons(): array
    {
        return [
            'anonymous ability' => [
                Exposure::Denied,
                InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY,
                'The ability "catalog.product_repository.save" is open to anonymous callers, and this store does not '
                . 'expose such abilities.',
            ],
            'a reason of another module' => [
                Exposure::Denied,
                'quiet_hours',
                'The ability "catalog.product_repository.save" is not exposed to MCP clients (quiet_hours).',
            ],
            'a read-only block with a reason of its own' => [
                Exposure::ReadOnlyBlocked,
                'maintenance_window',
                'The ability "catalog.product_repository.save" is not exposed to MCP clients (maintenance_window).',
            ],
        ];
    }

    #[DataProvider('policyReasons')]
    public function testAnExposureDenialReportsThePolicysReason(
        Exposure $exposure,
        string $reason,
        string $message
    ): void {
        $invoker = $this->invoker($exposure, true, null, new FixedExposurePolicy($exposure, null, $reason));

        $result = $invoker->invoke($this->invocation(self::WRITE, true));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame($reason, $result->getDenialReason());
        self::assertSame($message, $result->getMessage());
        self::assertSame([], $this->source()->invocations);
        self::assertSame([$result], $this->listener->results);
    }

    public function testAGuardRefusalDeniesTheCallWithTheGuardsMessageAndRunsNothing(): void
    {
        $guard = new RecordingGuard('Passwords are never set through MCP.');
        $invoker = $this->invoker(Exposure::Exposed, true, null, null, null, ['password' => $guard]);
        $arguments = ['passwordHash' => 'x'];

        $result = $invoker->invoke($this->invocation(self::WRITE)->withArguments($arguments));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_ARGUMENT_GUARD, $result->getDenialReason());
        self::assertSame('Passwords are never set through MCP.', $result->getMessage());
        self::assertSame([[self::WRITE, $arguments]], $guard->asked);
        self::assertSame([], $this->source()->invocations);
        self::assertSame(['started', 'finished'], $this->listener->events);
        self::assertSame([$result], $this->listener->results);
    }

    public function testGuardsAreAskedOnlyAfterTheConfirmation(): void
    {
        $guard = new RecordingGuard('Refused.');
        $invoker = $this->invoker(Exposure::NeedsConfirmation, true, null, null, null, ['refusing' => $guard]);

        $unconfirmed = $invoker->invoke($this->invocation(self::WRITE));
        self::assertSame(InvocationOutcome::NeedsConfirmation, $unconfirmed->getOutcome());
        self::assertSame([], $guard->asked, 'An unconfirmed call never reaches the guards.');

        $confirmed = $invoker->invoke($this->invocation(self::WRITE, true));
        self::assertSame(InvocationOutcome::Denied, $confirmed->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_ARGUMENT_GUARD, $confirmed->getDenialReason());
        self::assertCount(1, $guard->asked);
        self::assertSame([], $this->source()->invocations);
    }

    /**
     * @return array<string, array{Exposure, bool}>
     */
    public static function earlierRefusals(): array
    {
        return [
            'an exposure denial' => [Exposure::Denied, true],
            'read-only mode' => [Exposure::ReadOnlyBlocked, true],
            'the ACL' => [Exposure::Exposed, false],
        ];
    }

    #[DataProvider('earlierRefusals')]
    public function testAnEarlierRefusalNeverReachesTheGuards(Exposure $exposure, bool $allowed): void
    {
        $guard = new RecordingGuard('Refused.');

        $result = $this->invoker($exposure, $allowed, null, null, null, ['refusing' => $guard])
            ->invoke($this->invocation(self::WRITE, true));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertNotSame(InvocationResultInterface::DENIAL_ARGUMENT_GUARD, $result->getDenialReason());
        self::assertSame([], $guard->asked);
    }

    public function testTheFirstRefusingGuardDecidesAndTheGuardsAfterItAreNotAsked(): void
    {
        $passing = new RecordingGuard();
        $refusing = new RecordingGuard('The first refusal.');
        $later = new RecordingGuard('A later refusal.');
        $invoker = $this->invoker(Exposure::Exposed, true, null, null, null, [
            'passing' => $passing,
            'refusing' => $refusing,
            'later' => $later,
        ]);

        $result = $invoker->invoke($this->invocation(self::WRITE));

        self::assertSame('The first refusal.', $result->getMessage());
        self::assertCount(1, $passing->asked);
        self::assertCount(1, $refusing->asked);
        self::assertSame([], $later->asked);
    }

    public function testAnEmptyRefusalStillDeniesWithAMessageOfItsOwn(): void
    {
        $invoker = $this->invoker(Exposure::Exposed, true, null, null, null, ['blank' => new RecordingGuard(' ')]);

        $result = $invoker->invoke($this->invocation(self::WRITE));

        self::assertSame(InvocationResultInterface::DENIAL_ARGUMENT_GUARD, $result->getDenialReason());
        self::assertSame(
            'The arguments of this call of the ability "catalog.product_repository.save" are refused.',
            $result->getMessage()
        );
    }

    public function testGuardsThatLetTheCallThroughLeaveItToRun(): void
    {
        $guard = new RecordingGuard();

        $result = $this->invoker(Exposure::Exposed, true, null, null, null, ['passing' => $guard])
            ->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertCount(1, $guard->asked);
        self::assertCount(1, $this->source()->invocations);
    }

    public function testAnAclDenialNamesTheResourcesTheAbilityNeeds(): void
    {
        $result = $this->invoker(Exposure::Exposed, false)->invoke($this->invocation(self::WRITE));

        self::assertSame(
            'The ability "catalog.product_repository.save" needs ACL resources your role lacks: '
            . 'Magento_Catalog::products, Magento_Catalog::edit.',
            $result->getMessage()
        );
    }

    public function testAnUnconfirmedCallOfAnAbilityThatNeedsConfirmationRunsNothing(): void
    {
        $result = $this->invoker(Exposure::NeedsConfirmation, true)->invoke($this->invocation(self::WRITE));

        self::assertSame(InvocationOutcome::NeedsConfirmation, $result->getOutcome());
        self::assertSame('', $result->getDenialReason());
        self::assertSame(
            'The ability "catalog.product_repository.save" runs only when the call is confirmed.',
            $result->getMessage()
        );
        self::assertSame([], $this->source()->invocations);
        self::assertSame(['started', 'finished'], $this->listener->events);
    }

    public function testAConfirmedCallOfAnAbilityThatNeedsConfirmationRuns(): void
    {
        $result = $this->invoker(Exposure::NeedsConfirmation, true)->invoke($this->invocation(self::WRITE, true));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertCount(1, $this->source()->invocations);
    }

    public function testALocalizedExceptionFailsWithItsOwnMessageAndIsNotLogged(): void
    {
        $invoker = $this->invoker(Exposure::Exposed, true, static function (): never {
            throw new InputException(__('"%1" is required. Enter and try again.', 'searchCriteria'));
        });

        $result = $invoker->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertSame('"searchCriteria" is required. Enter and try again.', $result->getMessage());
        self::assertSame('', $result->getDenialReason());
        self::assertNull($result->getValue());
        self::assertSame([], $this->logger->records);
        self::assertSame([$result], $this->listener->results);
    }

    public function testAnyOtherExceptionFailsWithTheMaskedMessageAndIsLogged(): void
    {
        $failure = new \RuntimeException('SQLSTATE[42S02]: table secret_table is missing');
        $invoker = $this->invoker(Exposure::Exposed, true, static function () use ($failure): never {
            throw $failure;
        });

        $result = $invoker->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertSame(self::MASKED, $result->getMessage());
        self::assertSame(
            [
                'MCP ability "catalog.product_repository.get_list" failed: '
                . 'SQLSTATE[42S02]: table secret_table is missing',
            ],
            $this->logger->messages('error')
        );
        self::assertSame($failure, $this->logger->records[0]['context']['exception']);
    }

    public function testAnErrorFailsWithAGenericMessageAndIsLogged(): void
    {
        $invoker = $this->invoker(Exposure::Exposed, true, static function (): never {
            throw new \TypeError('Argument #1 ($sku) must be of type string, array given in /var/www/Secret.php');
        });

        $result = $invoker->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertSame('Internal error', $result->getMessage());
        self::assertCount(1, $this->logger->messages('error'));
        self::assertStringContainsString('Secret.php', $this->logger->messages('error')[0]);
    }

    public function testACheckThatThrowsFailsTheCallWithoutRunningItAndListenersHearOfIt(): void
    {
        $invoker = $this->invoker(
            Exposure::Exposed,
            true,
            null,
            new FixedExposurePolicy(Exposure::Exposed, new \RuntimeException('The override table is gone.'))
        );

        $result = $invoker->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertSame(self::MASKED, $result->getMessage());
        self::assertSame([], $this->source()->invocations);
        self::assertSame(['started', 'finished'], $this->listener->events);
    }

    /**
     * @return array<string, array{Exposure, bool, bool, InvocationOutcome}>
     */
    public static function everyOutcome(): array
    {
        return [
            'succeeded' => [Exposure::Exposed, true, false, InvocationOutcome::Succeeded],
            'denied' => [Exposure::Denied, true, false, InvocationOutcome::Denied],
            'needs confirmation' => [Exposure::NeedsConfirmation, true, false, InvocationOutcome::NeedsConfirmation],
            'failed' => [Exposure::Exposed, true, true, InvocationOutcome::Failed],
        ];
    }

    #[DataProvider('everyOutcome')]
    public function testAThrowingListenerNeverChangesTheOutcomeAndTheOthersStillHear(
        Exposure $exposure,
        bool $allowed,
        bool $sourceFails,
        InvocationOutcome $outcome
    ): void {
        $behaviour = $sourceFails
            ? static function (): never {
                throw new \RuntimeException('The service broke.');
            }
            : null;
        $after = new RecordingListener();
        $invoker = $this->invoker($exposure, $allowed, $behaviour, null, [
            'before' => $this->listener,
            'throwing' => new ThrowingListener(),
            'after' => $after,
        ]);
        $invocation = $this->invocation(self::READ);

        $result = $invoker->invoke($invocation);

        self::assertSame($outcome, $result->getOutcome());
        self::assertSame(['started', 'finished'], $this->listener->events);
        self::assertSame(['started', 'finished'], $after->events);
        self::assertSame([$invocation], $after->started);
        self::assertSame([$result], $after->results);
        $listenerErrors = array_values(array_filter(
            $this->logger->messages('error'),
            static fn (string $message): bool => str_starts_with($message, 'MCP invocation listener')
        ));
        self::assertSame(
            [
                'MCP invocation listener "throwing" failed in started(): The listener broke on start.',
                'MCP invocation listener "throwing" failed in finished(): The listener broke on finish.',
            ],
            $listenerErrors
        );
    }

    public function testListenersHearTheInvocationAndTheResultTheCallerGets(): void
    {
        $invocation = $this->invocation(self::READ)->withArguments(['sku' => 'MH01']);

        $result = $this->invoker(Exposure::Exposed, true)->invoke($invocation);

        self::assertSame([$invocation], $this->listener->started);
        self::assertSame([$result], $this->listener->results);
    }

    /**
     * @param Exposure $exposure
     * @param bool $allowed
     * @param (\Closure(AbilityInterface, array<string, mixed>): mixed)|null $behaviour
     * @param ExposurePolicyInterface|null $policy
     * @param array<string, InvocationListenerInterface>|null $listeners
     * @param array<string, InvocationGuardInterface> $guards
     * @return AbilityInvoker
     */
    private function invoker(
        Exposure $exposure,
        bool $allowed,
        ?\Closure $behaviour = null,
        ?ExposurePolicyInterface $policy = null,
        ?array $listeners = null,
        array $guards = []
    ): AbilityInvoker {
        $this->source = new FakeAbilitySource(
            'webapi',
            [
                new Ability(
                    self::READ,
                    'Product list',
                    'Magento_Catalog',
                    'webapi',
                    'GET /V1/products',
                    AbilityAccess::Read,
                    ['Magento_Catalog::products']
                ),
                new Ability(
                    self::WRITE,
                    'Save a product',
                    'Magento_Catalog',
                    'webapi',
                    'POST /V1/products',
                    AbilityAccess::Write,
                    ['Magento_Catalog::products', 'Magento_Catalog::edit']
                ),
            ],
            $allowed,
            $behaviour
        );
        $catalog = new AbilityCatalog(
            new AbilityPageInterfaceFactory(
                new ConstructingObjectManager([AbilityPageInterface::class => AbilityPage::class])
            ),
            $this->logger,
            ['webapi' => $this->source]
        );
        $errorProcessor = $this->createMock(ErrorProcessor::class);
        $errorProcessor->method('maskException')->willReturn(
            new WebapiException(
                new Phrase('Internal Error. Details are available in Magento log file. Report ID: webapi-1')
            )
        );

        return new AbilityInvoker(
            $catalog,
            $catalog,
            $policy ?? new FixedExposurePolicy($exposure),
            new InvocationResultInterfaceFactory(
                new ConstructingObjectManager([InvocationResultInterface::class => InvocationResult::class])
            ),
            new StepClock(),
            $errorProcessor,
            $this->logger,
            $listeners ?? ['recording' => $this->listener],
            $guards
        );
    }

    /**
     * @param string $ability
     * @param bool $confirmed
     * @return InvocationInterface
     */
    private function invocation(string $ability, bool $confirmed = false): InvocationInterface
    {
        return new Invocation(
            $ability,
            new Caller(UserContextInterface::USER_TYPE_ADMIN, 1),
            new Client('test client', '1.0'),
            'http',
            'session',
            $confirmed
        );
    }

    /**
     * @return FakeAbilitySource
     */
    private function source(): FakeAbilitySource
    {
        self::assertNotNull($this->source);

        return $this->source;
    }
}
