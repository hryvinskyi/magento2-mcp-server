<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Setup\Patch\Data;

use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Setup\Patch\Data\EnableMcpCacheType;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Framework\App\Cache\Manager;
use Magento\Framework\App\Cache\StateInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The data patch that turns the module's cache type on, applied to a store where that type is disabled
 */
class EnableMcpCacheTypeTest extends TestCase
{
    use BlocksRealGraphQlClient;

    /**
     * Whether the cache type was enabled before the test
     *
     * @var bool
     */
    private bool $wasEnabled = false;

    /**
     * Remember whether the type was on, so the test leaves the deployment configuration as it found it
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->wasEnabled = $this->freshState()->isEnabled(McpCache::TYPE_IDENTIFIER);
    }

    /**
     * Put the cache type back to the status it had before the test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->cacheManager()->setEnabled([McpCache::TYPE_IDENTIFIER], $this->wasEnabled);
    }

    public function testApplyingTheDisabledTypeEnablesIt(): void
    {
        $this->cacheManager()->setEnabled([McpCache::TYPE_IDENTIFIER], false);
        self::assertFalse($this->freshState()->isEnabled(McpCache::TYPE_IDENTIFIER));

        $patch = self::objectManager()->get(EnableMcpCacheType::class);
        self::assertInstanceOf(EnableMcpCacheType::class, $patch);

        self::assertSame($patch, $patch->apply());
        self::assertTrue($this->freshState()->isEnabled(McpCache::TYPE_IDENTIFIER));
    }

    public function testApplyingTheEnabledTypeLeavesItEnabled(): void
    {
        $this->cacheManager()->setEnabled([McpCache::TYPE_IDENTIFIER], true);

        $patch = self::objectManager()->get(EnableMcpCacheType::class);
        self::assertInstanceOf(EnableMcpCacheType::class, $patch);
        $patch->apply();

        self::assertTrue($this->freshState()->isEnabled(McpCache::TYPE_IDENTIFIER));
    }

    public function testThePatchDependsOnNothingAndHasNoAliases(): void
    {
        $patch = self::objectManager()->get(EnableMcpCacheType::class);
        self::assertInstanceOf(EnableMcpCacheType::class, $patch);

        self::assertSame([], $patch::getDependencies());
        self::assertSame([], $patch->getAliases());
    }

    /**
     * A state read from the deployment configuration anew, so a status the shared state already cached is not read
     *
     * @return StateInterface
     */
    private function freshState(): StateInterface
    {
        $state = self::objectManager()->create(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);

        return $state;
    }

    /**
     * The shared cache manager, which persists the status to the deployment configuration
     *
     * @return Manager
     */
    private function cacheManager(): Manager
    {
        $manager = self::objectManager()->get(Manager::class);
        self::assertInstanceOf(Manager::class, $manager);

        return $manager;
    }

    /**
     * The integration object manager
     *
     * @return ObjectManager
     */
    private static function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
