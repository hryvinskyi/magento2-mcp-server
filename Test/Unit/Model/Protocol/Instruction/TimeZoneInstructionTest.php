<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Instruction;

use Hryvinskyi\McpServer\Model\Protocol\Instruction\TimeZoneInstruction;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedStoreTimeZones;
use PHPUnit\Framework\TestCase;

/**
 * What the instructions say about timestamps and the store's time zones
 */
class TimeZoneInstructionTest extends TestCase
{
    private const UTC = 'Timestamps the abilities return and filter on, such as created_at and updated_at, are UTC.';
    private const BOUNDS = 'Turn a day or period the user names into UTC bounds before you filter, and show times in '
        . 'store time.';

    public function testStoreViewsSharingAZoneAreNamedAsOneStore(): void
    {
        $instruction = new TimeZoneInstruction(
            new FixedStoreTimeZones(['default' => 'America/Los_Angeles', 'trade' => 'America/Los_Angeles'])
        );

        self::assertSame(
            self::UTC . ' This store runs in the time zone America/Los_Angeles. ' . self::BOUNDS,
            $instruction->text()
        );
    }

    public function testStoreViewsInDifferentZonesAreEachNamedByCode(): void
    {
        $instruction = new TimeZoneInstruction(
            new FixedStoreTimeZones(['default' => 'America/Los_Angeles', 'trade' => 'Europe/Kyiv'])
        );

        self::assertSame(
            self::UTC . ' Its store views run in different time zones (default: America/Los_Angeles, trade: '
                . 'Europe/Kyiv); the store view of an order or a customer tells which applies. ' . self::BOUNDS,
            $instruction->text()
        );
    }

    public function testAStoreWithoutStoreViewsIsOnlyToldThatTimestampsAreUtc(): void
    {
        self::assertSame(self::UTC, (new TimeZoneInstruction(new FixedStoreTimeZones([])))->text());
    }
}
