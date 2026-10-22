<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Clock\ClockInterface;

/**
 * A monotonic clock that stands still until the test moves it on
 */
class ManualClock implements ClockInterface
{
    /**
     * @param float $now Milliseconds
     */
    public function __construct(
        private float $now = 1000.0
    ) {
    }

    /**
     * Move the clock forward
     *
     * @param float $seconds
     * @return void
     */
    public function advanceSeconds(float $seconds): void
    {
        $this->now += $seconds * 1000;
    }

    /**
     * @inheritDoc
     */
    public function nowMs(): float
    {
        return $this->now;
    }
}
