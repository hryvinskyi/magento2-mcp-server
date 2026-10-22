<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

/**
 * The rows of the catalog grid that a search, its filters and its order select
 *
 * - A filter compares one field of a row: "eq" (equal), "like" (contains, `%` ignored, case-insensitive), "in" and
 *   "nin" (one of a list or none of it). A filter on a field rows do not have, or with another condition, matches no
 *   row, so a filter the grid sends that this query cannot apply never widens the result.
 * - A search matches a row when every word of it, case-insensitive, is in the row's name, title or category.
 * - Rows are ordered by one field, ascending or descending; rows equal on it keep the order of their names.
 *
 * @phpstan-import-type AbilityRow from AbilityRows
 * @phpstan-type Condition array{field: string, condition: string, value: string|list<string>}
 */
class AbilityGridQuery
{
    private const FIELDS = ['name', 'title', 'source', 'category', 'access', 'exposure'];

    /**
     * The rows that pass every filter and match the search
     *
     * @param list<AbilityRow> $rows
     * @param list<Condition> $conditions
     * @param string $search
     * @return list<AbilityRow>
     */
    public function matching(array $rows, array $conditions, string $search): array
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->passes($row, $conditions) && $this->found($row, $words)
        ));
    }

    /**
     * The rows ordered by one field
     *
     * @param list<AbilityRow> $rows
     * @param string $field A field the rows have; any other orders them by name
     * @param bool $descending
     * @return list<AbilityRow>
     */
    public function sorted(array $rows, string $field, bool $descending): array
    {
        $field = in_array($field, self::FIELDS, true) ? $field : 'name';
        usort($rows, static function (array $left, array $right) use ($field, $descending): int {
            $order = strcasecmp($left[$field], $right[$field]);
            $order = $descending ? -$order : $order;

            return $order !== 0 ? $order : strcmp($left['name'], $right['name']);
        });

        return $rows;
    }

    /**
     * Whether a row passes every filter
     *
     * @param AbilityRow $row
     * @param list<Condition> $conditions
     * @return bool
     */
    private function passes(array $row, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            if (!in_array($condition['field'], self::FIELDS, true)) {
                return false;
            }

            $actual = $row[$condition['field']];
            $value = $condition['value'];
            $passes = match ($condition['condition']) {
                'eq' => is_string($value) && $actual === $value,
                'like' => is_string($value) && str_contains(mb_strtolower($actual), mb_strtolower(trim($value, '%'))),
                'in' => is_array($value) && in_array($actual, $value, true),
                'nin' => is_array($value) && !in_array($actual, $value, true),
                default => false,
            };
            if (!$passes) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every word is in the row's name, title or category
     *
     * @param AbilityRow $row
     * @param list<string> $words
     * @return bool
     */
    private function found(array $row, array $words): bool
    {
        $text = mb_strtolower($row['name'] . ' ' . $row['title'] . ' ' . $row['category']);
        foreach ($words as $word) {
            if (!str_contains($text, $word)) {
                return false;
            }
        }

        return true;
    }
}
