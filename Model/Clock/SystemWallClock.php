<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Clock;

/**
 * The system's time of day
 */
class SystemWallClock implements WallClockInterface
{
    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return time();
    }
}
