<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Clock\WallClockInterface;

/**
 * A time of day the test sets and moves
 */
class FixedWallClock implements WallClockInterface
{
    /**
     * @param int $now
     */
    public function __construct(
        public int $now = 1_800_000_000
    ) {
    }

    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return $this->now;
    }
}
