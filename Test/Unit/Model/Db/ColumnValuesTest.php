<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Db;

use Hryvinskyi\McpServer\Model\Db\ColumnValues;
use PHPUnit\Framework\TestCase;

/**
 * Column values read from a fetched row, and moments written as UTC date-times and read back
 */
class ColumnValuesTest extends TestCase
{
    /**
     * @var ColumnValues
     */
    private ColumnValues $columns;

    protected function setUp(): void
    {
        $this->columns = new ColumnValues();
    }

    public function testTextIsTheColumnAsAStringOrEmpty(): void
    {
        $row = ['name' => 'Client', 'type' => 2, 'none' => null, 'list' => ['a']];

        self::assertSame('Client', $this->columns->text($row, 'name'));
        self::assertSame('2', $this->columns->text($row, 'type'));
        self::assertSame('', $this->columns->text($row, 'none'));
        self::assertSame('', $this->columns->text($row, 'list'));
        self::assertSame('', $this->columns->text($row, 'missing'));
    }

    public function testANullableIntegerIsNullUnlessTheColumnHoldsANumber(): void
    {
        $row = ['id' => '42', 'none' => null, 'word' => 'x'];

        self::assertSame(42, $this->columns->nullableInt($row, 'id'));
        self::assertNull($this->columns->nullableInt($row, 'none'));
        self::assertNull($this->columns->nullableInt($row, 'word'));
        self::assertNull($this->columns->nullableInt($row, 'missing'));
    }

    public function testAMomentIsWrittenAsAUtcDateTimeAndReadBack(): void
    {
        self::assertSame('2027-01-15 08:00:00', $this->columns->dateTime(1_800_000_000));
        self::assertSame(1_800_000_000, $this->columns->timestamp(['at' => '2027-01-15 08:00:00'], 'at'));
    }

    public function testAColumnWithoutADateTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->columns->timestamp(['at' => null], 'at');
    }

    public function testAColumnWithTextThatIsNoDateTimeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->columns->timestamp(['at' => 'yesterday-ish'], 'at');
    }
}
