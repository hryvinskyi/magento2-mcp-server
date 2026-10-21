<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Clock;

/**
 * A monotonic clock for measuring how long something took
 *
 * A reading counts milliseconds from an arbitrary fixed point: only the difference of two readings taken in the same
 * process means anything. It is not the time of day and never goes backwards.
 */
interface ClockInterface
{
    /**
     * Milliseconds since the clock's fixed point
     *
     * @return float
     */
    public function nowMs(): float;
}
