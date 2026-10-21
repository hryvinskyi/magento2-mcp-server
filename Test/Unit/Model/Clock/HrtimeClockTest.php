<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Clock;

use Hryvinskyi\McpServer\Model\Clock\HrtimeClock;
use PHPUnit\Framework\TestCase;

class HrtimeClockTest extends TestCase
{
    public function testReadingsNeverGoBackwardsAndMeasureMilliseconds(): void
    {
        $clock = new HrtimeClock();

        $before = $clock->nowMs();
        usleep(2000);
        $after = $clock->nowMs();

        self::assertGreaterThanOrEqual(2.0, $after - $before);
        self::assertLessThan(2000.0, $after - $before);
    }
}
