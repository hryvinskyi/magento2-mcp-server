<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterfaceFactory;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Data\ExposureDecision;
use Hryvinskyi\McpServer\Model\Data\Invocation;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use Magento\Authorization\Model\UserContextInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Every value object built through its generated factory and the module's preferences
 */
class FactoriesTest extends TestCase
{
    public function testAnAbilityComesFromItsFactory(): void
    {
        $ability = $this->ability();

        self::assertInstanceOf(Ability::class, $ability);
        self::assertSame('catalog.product_repository.get_list', $ability->getName());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['Magento_Catalog::products'], $ability->getAclResources());
    }

    public function testTheFactoryRefusesABrokenName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(AbilityInterfaceFactory::class)->create([
            'name' => 'Catalog.ProductRepository.getList',
            'title' => 'Get product list',
            'category' => 'Magento_Catalog',
            'source' => 'webapi',
            'sourceReference' => 'GET /V1/products',
            'access' => AbilityAccess::Read,
        ]);
    }

    public function testAPageComesFromItsFactory(): void
    {
        $ability = $this->ability();
        $page = $this->service(AbilityPageInterfaceFactory::class)->create(['total' => 9, 'items' => [$ability]]);

        self::assertInstanceOf(AbilityPage::class, $page);
        self::assertSame(9, $page->getTotal());
        self::assertSame([$ability], $page->getItems());
    }

    public function testAnInvocationComesFromItsFactory(): void
    {
        $caller = $this->caller();
        $client = $this->client();
        $invocation = $this->service(InvocationInterfaceFactory::class)->create([
            'abilityName' => 'catalog.product_repository.get',
            'caller' => $caller,
            'client' => $client,
            'transport' => 'http',
            'sessionId' => 'b1946ac92492d2347c6235b4d2611184',
            'confirmed' => true,
        ]);

        self::assertInstanceOf(Invocation::class, $invocation);
        self::assertInstanceOf(Caller::class, $caller);
        self::assertInstanceOf(Client::class, $client);
        self::assertSame($caller, $invocation->getCaller());
        self::assertSame($client, $invocation->getClient());
        self::assertSame(7, $invocation->getCaller()->getUserId());
        self::assertSame('Desk Assistant', $invocation->getClient()->getName());
        self::assertTrue($invocation->isConfirmed());
    }

    /**
     * Client arguments reach the invocation unchanged, nested DI-looking keys included
     *
     * The object manager would turn a nested `instance` key into an object and an `argument` key into deployment
     * configuration if the arguments were handed to the factory.
     */
    public function testClientArgumentsArriveAsSent(): void
    {
        $arguments = [
            'product' => ['sku' => 'x', 'extension_attributes' => ['instance' => \stdClass::class]],
            'filter' => ['argument' => 'db'],
        ];

        $invocation = $this->service(InvocationInterfaceFactory::class)->create([
            'abilityName' => 'catalog.product_repository.save',
            'caller' => $this->caller(),
            'client' => $this->client(),
            'transport' => 'http',
        ])->withArguments($arguments);

        self::assertSame($arguments, $invocation->getArguments());
    }

    public function testAResultComesFromItsFactoryAndKeepsItsValueAsReturned(): void
    {
        $value = ['items' => [['instance' => \stdClass::class, 'argument' => 'db']], 'total_count' => 1];

        $result = $this->service(InvocationResultInterfaceFactory::class)->create([
            'outcome' => InvocationOutcome::Succeeded,
            'durationMs' => 4.2,
            'ability' => $this->ability(),
        ])->withValue($value);

        self::assertInstanceOf(InvocationResult::class, $result);
        self::assertSame($value, $result->getValue());
        self::assertSame(4.2, $result->getDurationMs());
    }

    public function testADeniedResultComesFromItsFactory(): void
    {
        $result = $this->service(InvocationResultInterfaceFactory::class)->create([
            'outcome' => InvocationOutcome::Denied,
            'durationMs' => 0.1,
            'message' => 'Read-only mode is on.',
            'denialReason' => 'read_only_mode',
        ]);

        self::assertSame('read_only_mode', $result->getDenialReason());
        self::assertNull($result->getValue());
    }

    public function testAnExposureDecisionComesFromItsFactory(): void
    {
        $decision = $this->service(ExposureDecisionInterfaceFactory::class)->create([
            'exposure' => Exposure::Denied,
            'denialReason' => 'anonymous_ability',
        ]);

        self::assertInstanceOf(ExposureDecision::class, $decision);
        self::assertSame(Exposure::Denied, $decision->getExposure());
        self::assertSame('anonymous_ability', $decision->getDenialReason());
    }

    /**
     * @return AbilityInterface
     */
    private function ability(): AbilityInterface
    {
        return $this->service(AbilityInterfaceFactory::class)->create([
            'name' => 'catalog.product_repository.get_list',
            'title' => 'Get product list',
            'category' => 'Magento_Catalog',
            'source' => 'webapi',
            'sourceReference' => 'GET /V1/products',
            'access' => AbilityAccess::Read,
            'aclResources' => ['Magento_Catalog::products'],
        ]);
    }

    /**
     * @return CallerInterface
     */
    private function caller(): CallerInterface
    {
        return $this->service(CallerInterfaceFactory::class)->create([
            'userType' => UserContextInterface::USER_TYPE_ADMIN,
            'userId' => 7,
        ]);
    }

    /**
     * @return ClientInterface
     */
    private function client(): ClientInterface
    {
        return $this->service(ClientInterfaceFactory::class)->create([
            'name' => 'Desk Assistant',
            'version' => '1.4.2',
        ]);
    }

    /**
     * A service from the real container, typed
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
