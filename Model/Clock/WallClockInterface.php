<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Clock;

/**
 * The time of day, for anything stored and compared across requests, such as when a session was last seen
 *
 * Unlike the monotonic ClockInterface, a reading means the same thing in every process, and a clock adjustment can
 * move it.
 */
interface WallClockInterface
{
    /**
     * Seconds since the Unix epoch, in UTC
     *
     * @return int
     */
    public function now(): int;
}
