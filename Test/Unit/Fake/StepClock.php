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
 * A clock that moves forward by the same step at every reading
 */
class StepClock implements ClockInterface
{
    /**
     * @param float $now First reading
     * @param float $step Milliseconds added after every reading
     */
    public function __construct(
        private float $now = 1000.0,
        private readonly float $step = 7.5
    ) {
    }

    /**
     * @inheritDoc
     */
    public function nowMs(): float
    {
        $reading = $this->now;
        $this->now += $this->step;

        return $reading;
    }
}
