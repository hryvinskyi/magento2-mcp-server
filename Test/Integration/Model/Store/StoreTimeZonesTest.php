<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Store;

use Hryvinskyi\McpServer\Model\Store\StoreTimeZones;
use Hryvinskyi\McpServer\Model\Store\StoreTimeZonesInterface;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The time zones of the store views, read through the container's port from the locale setting of each store view
 */
#[DbIsolation(true)]
class StoreTimeZonesTest extends TestCase
{
    #[ConfigFixture('general/locale/timezone', 'Europe/Kyiv', 'store', 'default')]
    public function testEachStoreViewRunsInTheZoneItsLocaleSettingNamesAndTheAdminStoreViewIsNotListed(): void
    {
        $timeZones = Bootstrap::getObjectManager()->get(StoreTimeZonesInterface::class);
        self::assertInstanceOf(StoreTimeZones::class, $timeZones);

        $zones = $timeZones->zones();

        self::assertSame('Europe/Kyiv', $zones['default'] ?? null);
        self::assertArrayNotHasKey('admin', $zones);
    }
}
