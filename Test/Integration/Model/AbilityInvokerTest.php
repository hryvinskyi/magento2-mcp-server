<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model;

use Hryvinskyi\McpServer\Api\AbilityInvokerInterface;
use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\AbilityOverrideRepository;
use Hryvinskyi\McpServer\Model\Config;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingListener;
use Magento\Authorization\Model\UserContextInterface;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The invoker with the container's own exposure policy, guards, clock, error masking and result factory, over a
 * catalog holding one test source
 */
#[DbIsolation(true)]
class AbilityInvokerTest extends TestCase
{
    private const READ = 'catalog.product_repository.get_list';
    private const WRITE = 'catalog.product_repository.save';
    private const CUSTOMER_SAVE = 'customer.customer_repository.save';
    private const ANONYMOUS = 'directory.country_information_acquirer.get_countries_info';

    /**
     * @var FakeAbilitySource
     */
    private FakeAbilitySource $source;

    /**
     * @var RecordingListener
     */
    private RecordingListener $listener;

    /**
     * @var AbilityInvoker
     */
    private AbilityInvoker $invoker;

    /**
     * The shared repository keeps what it read; a previous test's rows were rolled back under it
     *
     * @return void
     */
    protected function setUp(): void
    {
        $repository = Bootstrap::getObjectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepository::class, $repository);
        $repository->_resetState();

        $this->source = new FakeAbilitySource(
            'test',
            [
                $this->ability(self::READ, AbilityAccess::Read),
                $this->ability(self::WRITE, AbilityAccess::Write),
                $this->ability(self::CUSTOMER_SAVE, AbilityAccess::Write, ['Magento_Customer::manage']),
                $this->ability(self::ANONYMOUS, AbilityAccess::Read, ['anonymous']),
            ],
            true,
            static fn (AbilityInterface $ability, array $arguments): array => ['items' => [], 'asked' => $arguments]
        );
        $this->listener = new RecordingListener();
        $this->invoker = $this->invokerOver($this->source);
    }

    public function testThePreferenceIsTheInvoker(): void
    {
        self::assertInstanceOf(
            AbilityInvoker::class,
            Bootstrap::getObjectManager()->get(AbilityInvokerInterface::class)
        );
    }

    public function testAReadRunsAfterInstallAndListenersHearIt(): void
    {
        $result = $this->invoker->invoke($this->invocation(self::READ)->withArguments(['pageSize' => 5]));

        self::assertInstanceOf(InvocationResult::class, $result);
        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertSame(['items' => [], 'asked' => ['pageSize' => 5]], $result->getValue());
        self::assertGreaterThanOrEqual(0.0, $result->getDurationMs());
        self::assertSame(['started', 'finished'], $this->listener->events);
        self::assertSame([$result], $this->listener->results);
    }

    public function testAfterInstallReadOnlyModeDeniesAWriteAndRunsNothing(): void
    {
        $result = $this->invoker->invoke($this->invocation(self::WRITE));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_READ_ONLY_MODE, $result->getDenialReason());
        self::assertSame([], $this->source->invocations);
        self::assertSame(['started', 'finished'], $this->listener->events);
    }

    public function testAStoredDenialDeniesARead(): void
    {
        $this->storeOverride(self::READ, true, false);

        $result = $this->invoker->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_DENY_LIST, $result->getDenialReason());
        self::assertSame([], $this->source->invocations);
    }

    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testAStoredConfirmationIsAskedThenHonoured(): void
    {
        $this->storeOverride(self::WRITE, false, true);

        $unconfirmed = $this->invoker->invoke($this->invocation(self::WRITE));
        $confirmed = $this->invoker->invoke($this->invocation(self::WRITE, true));

        self::assertSame(InvocationOutcome::NeedsConfirmation, $unconfirmed->getOutcome());
        self::assertSame(InvocationOutcome::Succeeded, $confirmed->getOutcome());
        self::assertCount(1, $this->source->invocations);
    }

    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testTheWiredGuardRefusesACustomerSaveCarryingAPasswordHash(): void
    {
        $refused = $this->invoker->invoke(
            $this->invocation(self::CUSTOMER_SAVE)->withArguments([
                'customer' => ['email' => 'guarded@example.com'],
                'passwordHash' => 'a hash the caller chose',
            ])
        );
        $allowed = $this->invoker->invoke(
            $this->invocation(self::CUSTOMER_SAVE)->withArguments(['customer' => ['email' => 'guarded@example.com']])
        );

        self::assertSame(InvocationOutcome::Denied, $refused->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_ARGUMENT_GUARD, $refused->getDenialReason());
        self::assertStringStartsWith('Passwords are never set through MCP', $refused->getMessage());
        self::assertSame(InvocationOutcome::Succeeded, $allowed->getOutcome());
        self::assertSame(
            [[self::CUSTOMER_SAVE, ['customer' => ['email' => 'guarded@example.com']]]],
            $this->source->invocations
        );
        self::assertSame([$refused, $allowed], $this->listener->results);
    }

    public function testAfterInstallAnAbilityAnyoneMayCallIsDeniedAndRunsNothing(): void
    {
        $result = $this->invoker->invoke($this->invocation(self::ANONYMOUS));

        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY, $result->getDenialReason());
        self::assertSame([], $this->source->invocations);
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testAnAbilityAnyoneMayCallRunsOnceSuchAbilitiesAreExposed(): void
    {
        $result = $this->invoker->invoke($this->invocation(self::ANONYMOUS));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
    }

    public function testAServiceFailureIsMaskedByTheWebApiErrorProcessor(): void
    {
        $failing = new FakeAbilitySource(
            'test',
            [$this->ability(self::READ, AbilityAccess::Read)],
            true,
            static function (): never {
                throw new \RuntimeException('The service broke.');
            }
        );

        $result = $this->invokerOver($failing)->invoke($this->invocation(self::READ));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertNotSame('', trim($result->getMessage()));
        self::assertSame(['started', 'finished'], $this->listener->events);
    }

    /**
     * The container's invoker over a catalog holding only this source, heard by the recording listener
     *
     * @param FakeAbilitySource $source
     * @return AbilityInvoker
     */
    private function invokerOver(FakeAbilitySource $source): AbilityInvoker
    {
        $catalog = Bootstrap::getObjectManager()->create(AbilityCatalog::class, ['sources' => ['test' => $source]]);
        $invoker = Bootstrap::getObjectManager()->create(AbilityInvoker::class, [
            'catalog' => $catalog,
            'sourceResolver' => $catalog,
            'listeners' => ['recording' => $this->listener],
        ]);
        self::assertInstanceOf(AbilityInvoker::class, $invoker);

        return $invoker;
    }

    /**
     * @param string $name
     * @param bool $confirmed
     * @return InvocationInterface
     */
    private function invocation(string $name, bool $confirmed = false): InvocationInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $callers = $objectManager->get(CallerInterfaceFactory::class);
        self::assertInstanceOf(CallerInterfaceFactory::class, $callers);
        $clients = $objectManager->get(ClientInterfaceFactory::class);
        self::assertInstanceOf(ClientInterfaceFactory::class, $clients);
        $invocations = $objectManager->get(InvocationInterfaceFactory::class);
        self::assertInstanceOf(InvocationInterfaceFactory::class, $invocations);

        return $invocations->create([
            'abilityName' => $name,
            'caller' => $callers->create(['userType' => UserContextInterface::USER_TYPE_ADMIN, 'userId' => 1]),
            'client' => $clients->create(['name' => 'integration test', 'version' => '1.0']),
            'transport' => 'http',
            'confirmed' => $confirmed,
        ]);
    }

    /**
     * @param string $ability
     * @param bool $denied
     * @param bool $needsConfirmation
     * @return void
     */
    private function storeOverride(string $ability, bool $denied, bool $needsConfirmation): void
    {
        $override = Bootstrap::getObjectManager()->create(AbilityOverrideInterface::class);
        self::assertInstanceOf(AbilityOverrideInterface::class, $override);
        $repository = Bootstrap::getObjectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepositoryInterface::class, $repository);

        $repository->save(
            $override->setAbility($ability)->setDenied($denied)->setNeedsConfirmation($needsConfirmation)
        );
    }

    /**
     * @param string $name
     * @param AbilityAccess $access
     * @param list<string> $aclResources
     * @return AbilityInterface
     */
    private function ability(string $name, AbilityAccess $access, array $aclResources = []): AbilityInterface
    {
        $factory = Bootstrap::getObjectManager()->get(AbilityInterfaceFactory::class);
        self::assertInstanceOf(AbilityInterfaceFactory::class, $factory);

        return $factory->create([
            'name' => $name,
            'title' => 'Ability',
            'category' => 'Magento_Catalog',
            'source' => 'test',
            'sourceReference' => 'GET /V1/products',
            'access' => $access,
            'aclResources' => $aclResources,
        ]);
    }
}
