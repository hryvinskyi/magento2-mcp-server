<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Clock;

/**
 * The system's high-resolution monotonic clock
 *
 * Unlike the time of day, it is not moved by a clock adjustment, so the difference of two readings is a duration.
 */
class HrtimeClock implements ClockInterface
{
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    /**
     * @inheritDoc
     */
    public function nowMs(): float
    {
        return hrtime(true) / self::NANOSECONDS_PER_MILLISECOND;
    }
}
