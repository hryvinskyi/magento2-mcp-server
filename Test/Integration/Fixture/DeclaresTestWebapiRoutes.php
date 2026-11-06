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
use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Source\Webapi\Metadata\ServiceMetadataReader;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteAbilityBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteIndex;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\CachedInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiInvoker;
use Magento\Framework\App\Cache\StateInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\Webapi\Model\Cache\Type\Webapi as WebapiCache;
use Magento\Webapi\Model\Config as WebapiConfig;
use Magento\Webapi\Model\Config\Reader as WebapiConfigReader;
use Magento\Webapi\Model\ServiceMetadata;
use PHPUnit\Framework\Attributes\After;

/**
 * Adds the routes of `webapi.xml` next to this trait to the store's Web API configuration, for the test that asks
 *
 * They route the repository of Magento's UI bookmarks, a module this one depends on, and this module's own exposure
 * override repository, so a test can read the schema of a search-criteria list, of a data-object save and of an
 * update route that takes the record's key in its path, and call them, on any store. No module this one declares
 * routes such services itself.
 *
 * Magento reads the routes through its own Web API configuration reader, given a file resolver that adds the test
 * file to the modules' files, so both ways the module reads service metadata see them. The Web API configuration
 * cache and this module's cache, which keeps the input schemas built from routes, are switched off meanwhile, so
 * nothing built from the test routes is stored. After the test both are put back as they were, and the shared Web
 * API configuration, service metadata, the module's Web API source chain, the ability catalog and the invoker are
 * dropped, so the next test builds them from the store's own routes.
 */
trait DeclaresTestWebapiRoutes
{
    /**
     * Whether each cache switched off for the test routes was on before; empty while the routes are not declared
     *
     * @var array<string, bool>
     */
    private array $webapiCachesEnabledBefore = [];

    /**
     * From now until the end of the test, the store's Web API configuration holds the test routes too
     *
     * @return void
     */
    protected function declareTestWebapiRoutes(): void
    {
        $objectManager = $this->webapiRoutesObjectManager();
        $state = $this->webapiCacheState();
        foreach ([WebapiCache::TYPE_IDENTIFIER, McpCache::TYPE_IDENTIFIER] as $type) {
            $this->webapiCachesEnabledBefore[$type] ??= $state->isEnabled($type);
            $state->setEnabled($type, false);
        }

        $reader = $objectManager->create(WebapiConfigReader::class, [
            'fileResolver' => $objectManager->create(TestWebapiRoutesFileResolver::class),
        ]);
        self::assertInstanceOf(WebapiConfigReader::class, $reader);
        $this->forgetWebapiConfiguration();
        $objectManager->addSharedInstance($reader, WebapiConfigReader::class);
    }

    /**
     * Take the test routes out again and put the caches back as they were
     *
     * @return void
     */
    #[After]
    protected function withdrawTestWebapiRoutes(): void
    {
        if ($this->webapiCachesEnabledBefore === []) {
            return;
        }

        $this->forgetWebapiConfiguration();
        $state = $this->webapiCacheState();
        foreach ($this->webapiCachesEnabledBefore as $type => $enabledBefore) {
            $state->setEnabled($type, $enabledBefore);
        }
        $this->webapiCachesEnabledBefore = [];
    }

    /**
     * Drop the shared reader and every shared service that holds what it read
     *
     * @return void
     */
    private function forgetWebapiConfiguration(): void
    {
        $objectManager = $this->webapiRoutesObjectManager();
        foreach ([
            WebapiConfigReader::class,
            WebapiConfig::class,
            ServiceMetadata::class,
            ServiceMetadataReader::class,
            RouteIndex::class,
            RouteAbilityBuilder::class,
            InputSchemaBuilder::class,
            CachedInputSchemaBuilder::class,
            WebapiInvoker::class,
            WebapiAbilitySource::class,
            AbilityCatalog::class,
            AbilityInvoker::class,
        ] as $holder) {
            $objectManager->removeSharedInstance($holder);
        }
    }

    /**
     * @return StateInterface
     */
    private function webapiCacheState(): StateInterface
    {
        $state = $this->webapiRoutesObjectManager()->get(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);

        return $state;
    }

    /**
     * @return ObjectManager
     */
    private function webapiRoutesObjectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
