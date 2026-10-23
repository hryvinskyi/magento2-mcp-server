<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingListener;
use Magento\Authorization\Model\UserContextInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * The container's own invoker over a catalog of one test source, called by the test admin
 */
trait InvokesTestAbilities
{
    /**
     * The container's invoker over a catalog holding only this source, heard by the listener
     *
     * @param FakeAbilitySource $source
     * @param RecordingListener $listener
     * @return AbilityInvoker
     */
    private function invokerOver(FakeAbilitySource $source, RecordingListener $listener): AbilityInvoker
    {
        $catalog = Bootstrap::getObjectManager()->create(AbilityCatalog::class, ['sources' => ['test' => $source]]);
        $invoker = Bootstrap::getObjectManager()->create(AbilityInvoker::class, [
            'catalog' => $catalog,
            'sourceResolver' => $catalog,
            'listeners' => ['recording' => $listener],
        ]);
        self::assertInstanceOf(AbilityInvoker::class, $invoker);

        return $invoker;
    }

    /**
     * A call of the ability by the test admin
     *
     * @param string $name
     * @return InvocationInterface
     */
    private function invocation(string $name): InvocationInterface
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
            'confirmed' => false,
        ]);
    }

    /**
     * A reading ability of the test source
     *
     * @param string $name
     * @param list<string> $aclResources
     * @return AbilityInterface
     */
    private function readingAbility(string $name, array $aclResources): AbilityInterface
    {
        $factory = Bootstrap::getObjectManager()->get(AbilityInterfaceFactory::class);
        self::assertInstanceOf(AbilityInterfaceFactory::class, $factory);

        return $factory->create([
            'name' => $name,
            'title' => 'Ability',
            'category' => 'Test',
            'source' => 'test',
            'sourceReference' => 'GET /V1/things',
            'access' => AbilityAccess::Read,
            'aclResources' => $aclResources,
        ]);
    }
}
