<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Source\Pool;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\AbilityInvokerInterface;
use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\AbilityOverrideRepository;
use Hryvinskyi\McpServer\Model\Source\Pool\PoolAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiAbilitySource;
use Hryvinskyi\McpServer\Test\Integration\Fixture\AdminRoles;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\McpAcl;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakePoolAbility;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\Authorization as FrameworkAuthorization;
use Magento\Framework\Webapi\Authorization as WebapiAuthorization;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\Webapi\Model\WebapiRoleLocator;
use PHPUnit\Framework\TestCase;

/**
 * Abilities registered in the pool source, seen through the catalog and the invoker the container builds from
 * di.xml, with only the registered items added to the pool
 */
#[AppArea('webapi_rest')]
#[DbIsolation(true)]
class PoolAbilitySourceTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use SwitchesGraphQlSourceOff;

    private const QUOTE = 'acme_shipping.rate_service.quote';
    private const GET_LIST = 'catalog.product_repository.get_list';

    /**
     * Shared instances that hold the pool's items or a user context, built again for each test
     */
    private const REBUILT = [
        WebapiRoleLocator::class,
        FrameworkAuthorization::class,
        WebapiAuthorization::class,
        McpAcl::AUTHORIZATION,
        McpAcl::WEBAPI_AUTHORIZATION,
        WebapiAbilitySource::class,
        PoolAbilitySource::class,
        AbilityCatalog::class,
        AbilityInvoker::class,
    ];

    protected function setUp(): void
    {
        $this->forgetSharedInstances();
        $repository = self::objectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepository::class, $repository);
        $repository->_resetState();
    }

    protected function tearDown(): void
    {
        $this->forgetSharedInstances();
    }

    public function testARegisteredAbilityIsListedByTheCatalogAndRunsThroughTheInvoker(): void
    {
        $this->actAs(AdminRoles::grant(['Magento_Sales::sales'], 990101));
        $quote = new FakePoolAbility(
            self::QUOTE,
            ['Magento_Sales::sales'],
            inputSchema: ['type' => 'object', 'properties' => []]
        );
        $this->register($quote);

        $ability = $this->catalog()->get(self::QUOTE);
        $result = $this->invoker()->invoke($this->invocation(self::QUOTE)->withArguments(['country' => 'UA']));

        self::assertSame(PoolAbilitySource::CODE, $ability->getSource());
        self::assertSame('pool:' . self::QUOTE, $ability->getSourceReference());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['Magento_Sales::sales'], $ability->getAclResources());
        self::assertSame(
            '{"type":"object","properties":{}}',
            json_encode($this->catalog()->schemaOf($ability), JSON_THROW_ON_ERROR)
        );
        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertSame(['asked' => ['country' => 'UA']], $result->getValue());
        self::assertSame([['country' => 'UA']], $quote->calls);
    }

    public function testARegisteredAbilityWinsTheNameOfAWebApiAbility(): void
    {
        $this->actAs(AdminRoles::grant(['Magento_Catalog::products'], 990102));
        $registered = new FakePoolAbility(self::GET_LIST, ['Magento_Catalog::products']);
        $this->register($registered);

        $ability = $this->catalog()->get(self::GET_LIST);
        $result = $this->invoker()->invoke($this->invocation(self::GET_LIST)->withArguments(['pageSize' => 1]));

        self::assertSame(PoolAbilitySource::CODE, $ability->getSource());
        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertSame([['pageSize' => 1]], $registered->calls);
        self::assertSame(
            WebapiAbilitySource::CODE,
            $this->catalog()->get('catalog.product_repository.save')->getSource(),
            'The Web API still offers every name the pool does not take.'
        );
    }

    public function testACallerWithoutTheAbilityResourceIsDeniedAndTheItemNeverRuns(): void
    {
        $this->actAs(AdminRoles::grant(['Magento_Catalog::products'], 990103));
        $quote = new FakePoolAbility(self::QUOTE, ['Magento_Sales::sales']);
        $this->register($quote);

        $result = $this->invoker()->invoke($this->invocation(self::QUOTE));

        self::assertFalse($this->catalog()->isAllowed($this->catalog()->get(self::QUOTE)));
        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_ACL, $result->getDenialReason());
        self::assertSame([], $quote->calls);
    }

    /**
     * Put these items in the pool source the catalog's di.xml `sources` resolves
     *
     * @param FakePoolAbility ...$items
     * @return void
     */
    private function register(FakePoolAbility ...$items): void
    {
        $abilities = [];
        foreach ($items as $item) {
            $abilities[$item->getName()] = $item;
        }

        $objectManager = self::objectManager();
        $source = $objectManager->create(PoolAbilitySource::class, ['abilities' => $abilities]);
        self::assertInstanceOf(PoolAbilitySource::class, $source);
        foreach ([PoolAbilitySource::class, AbilityCatalog::class, AbilityInvoker::class] as $class) {
            $objectManager->removeSharedInstance($class);
        }
        $objectManager->addSharedInstance($source, PoolAbilitySource::class);
    }

    /**
     * Make this admin the caller of everything built from now on
     *
     * @param UserContextInterface $caller
     * @return void
     */
    private function actAs(UserContextInterface $caller): void
    {
        $objectManager = self::objectManager();
        $this->forgetSharedInstances();
        $objectManager->addSharedInstance($caller, UserContextInterface::class, true);
        $aclBuilder = $objectManager->get(AclBuilder::class);
        self::assertInstanceOf(AclBuilder::class, $aclBuilder);
        $aclBuilder->resetRuntimeAcl();
    }

    /**
     * @return void
     */
    private function forgetSharedInstances(): void
    {
        $objectManager = self::objectManager();
        $objectManager->removeSharedInstance(UserContextInterface::class, true);
        foreach (self::REBUILT as $class) {
            $objectManager->removeSharedInstance($class);
        }
    }

    /**
     * @param string $name
     * @return InvocationInterface
     */
    private function invocation(string $name): InvocationInterface
    {
        $objectManager = self::objectManager();
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
            'confirmed' => false,
        ]);
    }

    /**
     * @return AbilityCatalogInterface
     */
    private function catalog(): AbilityCatalogInterface
    {
        $catalog = self::objectManager()->get(AbilityCatalogInterface::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        return $catalog;
    }

    /**
     * @return AbilityInvokerInterface
     */
    private function invoker(): AbilityInvokerInterface
    {
        $invoker = self::objectManager()->get(AbilityInvokerInterface::class);
        self::assertInstanceOf(AbilityInvoker::class, $invoker);

        return $invoker;
    }

    /**
     * @return ObjectManager
     */
    private static function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
