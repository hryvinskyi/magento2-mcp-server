<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Db;

/**
 * Converts between the module's table columns and PHP values
 *
 * Times are stored as UTC date-times, which is how Magento's database connection reads and writes them, and handled
 * in PHP as seconds since the Unix epoch.
 */
class ColumnValues
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * A column of a fetched row as text; '' when it is missing or null
     *
     * @param array<mixed> $row
     * @param string $column
     * @return string
     */
    public function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A nullable integer column of a fetched row; null when it is missing, null or not a number
     *
     * @param array<mixed> $row
     * @param string $column
     * @return int|null
     */
    public function nullableInt(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The seconds since the epoch of a UTC date-time column of a fetched row
     *
     * @param array<mixed> $row
     * @param string $column
     * @return int
     * @throws \InvalidArgumentException When the column holds no date-time
     */
    public function timestamp(array $row, string $column): int
    {
        $value = $this->text($row, $column);
        $time = $value === '' ? false : strtotime($value . ' UTC');
        if ($time === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a date-time.', $value));
        }

        return $time;
    }

    /**
     * A moment as a UTC date-time for a column
     *
     * @param int $time Seconds since the epoch
     * @return string
     */
    public function dateTime(int $time): string
    {
        return gmdate(self::DATE_FORMAT, $time);
    }
}
