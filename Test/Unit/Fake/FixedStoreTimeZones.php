<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Store\StoreTimeZonesInterface;

/**
 * Store views that run in the time zones the test gives
 */
class FixedStoreTimeZones implements StoreTimeZonesInterface
{
    /**
     * @param array<string, string> $zones Time zones keyed by store view code
     */
    public function __construct(
        private readonly array $zones = ['default' => 'UTC']
    ) {
    }

    /**
     * @inheritDoc
     */
    public function zones(): array
    {
        return $this->zones;
    }
}
