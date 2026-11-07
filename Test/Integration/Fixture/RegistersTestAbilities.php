<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Admin\CatalogSections;
use Hryvinskyi\McpServer\Model\Admin\ExposureChanges;
use Hryvinskyi\McpServer\Model\Http\Verb\EndSession;
use Hryvinskyi\McpServer\Model\Http\Verb\PostMessages;
use Hryvinskyi\McpServer\Model\Protocol\Dispatcher;
use Hryvinskyi\McpServer\Model\Protocol\Method\ToolsCall;
use Hryvinskyi\McpServer\Model\Protocol\Method\ToolsList;
use Hryvinskyi\McpServer\Model\Protocol\Server;
use Hryvinskyi\McpServer\Model\Protocol\Tool\DiscoverAbilities;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ExecuteAbility;
use Hryvinskyi\McpServer\Model\Protocol\Tool\GetAbilityInfo;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolRegistry;
use Hryvinskyi\McpServer\Model\Source\Pool\PoolAbilitySource;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\Attributes\After;

/**
 * Test abilities in the pool source the container wires, for tests that must run, confirm or deny a call without
 * depending on any store module
 *
 * registerTestAbilities() makes a pool source holding only the given RecordingPoolAbility items the shared one, and
 * drops the shared services of this module that hold the catalog, from the invoker up to the protocol server and
 * the admin catalog, so they are built again over it. After the test the shared pool source is dropped as well, and
 * the next one is built from di.xml again.
 *
 * A service outside this module that holds one of them, such as Magento's REST front controller, is built again only
 * by a fresh application: an endpoint test registers its abilities in an isolated test, before its first request.
 */
trait RegistersTestAbilities
{
    /**
     * The test abilities registered by the running test, by name
     *
     * @var array<string, RecordingPoolAbility>
     */
    private array $testAbilities = [];

    /**
     * Make a pool source holding exactly these test abilities the one every catalog the container builds lists
     *
     * @param array<string, array{
     *     title: string,
     *     access: string,
     *     aclResources: list<string>,
     *     answer?: list<array<string, string>>
     * }> $abilities By name; `answer` is what every call returns, the call's arguments under `saved` without it
     * @return void
     */
    protected function registerTestAbilities(array $abilities): void
    {
        $objectManager = $this->testAbilitiesObjectManager();
        $this->testAbilities = [];
        foreach ($abilities as $name => $ability) {
            $item = $objectManager->create(RecordingPoolAbility::class, ['name' => $name] + $ability);
            self::assertInstanceOf(RecordingPoolAbility::class, $item);
            $this->testAbilities[$name] = $item;
        }

        $source = $objectManager->create(PoolAbilitySource::class, ['abilities' => $this->testAbilities]);
        self::assertInstanceOf(PoolAbilitySource::class, $source);
        $this->forgetCatalogHolders();
        $objectManager->addSharedInstance($source, PoolAbilitySource::class);
    }

    /**
     * Register the test ability that writes and needs this module's endpoint resource, RecordingPoolAbility::SAVE
     *
     * @return RecordingPoolAbility
     */
    protected function registerTestSaveAbility(): RecordingPoolAbility
    {
        $this->registerTestAbilities([RecordingPoolAbility::SAVE => [
            'title' => 'Save a test record',
            'access' => 'write',
            'aclResources' => ['Hryvinskyi_McpServer::access'],
        ]]);

        return $this->testAbility(RecordingPoolAbility::SAVE);
    }

    /**
     * The registered test ability of this name, which records what the pool source ran
     *
     * @param string $name
     * @return RecordingPoolAbility
     */
    protected function testAbility(string $name): RecordingPoolAbility
    {
        $ability = $this->testAbilities[$name] ?? null;
        self::assertInstanceOf(RecordingPoolAbility::class, $ability, sprintf('No test ability %s.', $name));

        return $ability;
    }

    /**
     * Drop the test pool source and everything built over it, so the next test builds them from di.xml
     *
     * @return void
     */
    #[After]
    protected function unregisterTestAbilities(): void
    {
        if ($this->testAbilities === []) {
            return;
        }

        $this->forgetCatalogHolders();
        $this->testAbilities = [];
    }

    /**
     * Drop the shared pool source and this module's shared services that hold the catalog built over it
     *
     * @return void
     */
    private function forgetCatalogHolders(): void
    {
        $objectManager = $this->testAbilitiesObjectManager();
        foreach ([
            PoolAbilitySource::class,
            AbilityCatalog::class,
            AbilityInvoker::class,
            DiscoverAbilities::class,
            GetAbilityInfo::class,
            ExecuteAbility::class,
            ToolRegistry::class,
            ToolsList::class,
            ToolsCall::class,
            Dispatcher::class,
            Server::class,
            PostMessages::class,
            EndSession::class,
            AbilityRows::class,
            CatalogSections::class,
            ExposureChanges::class,
        ] as $holder) {
            $objectManager->removeSharedInstance($holder);
        }
    }

    /**
     * @return ObjectManager
     */
    private function testAbilitiesObjectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
