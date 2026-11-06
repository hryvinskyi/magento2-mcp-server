<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin;

use Hryvinskyi\McpServer\Model\Admin\AbilityGridQuery;
use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type AbilityRow from AbilityRows
 */
class AbilityGridQueryTest extends TestCase
{
    public function testFiltersAreAllAppliedAndASearchNeedsEveryWord(): void
    {
        $query = new AbilityGridQuery();
        $rows = $this->rows();

        self::assertSame(
            ['store.store_repository.save'],
            $this->names($query->matching($rows, [
                ['field' => 'source', 'condition' => 'eq', 'value' => 'webapi'],
                ['field' => 'access', 'condition' => 'eq', 'value' => 'write'],
            ], ''))
        );
        self::assertSame(
            ['store.store_repository.save', 'store.store_repository.get_list'],
            $this->names($query->matching($rows, [], 'STORE magento')),
            'Matching keeps the rows in the order they came'
        );
        self::assertSame(
            ['graphql.query.store_config'],
            $this->names(
                $query->matching($rows, [['field' => 'name', 'condition' => 'like', 'value' => '%Query%']], '')
            )
        );
    }

    public function testSelectionsByListAreKeptOrLeftOut(): void
    {
        $query = new AbilityGridQuery();
        $saved = ['store.store_repository.save'];

        self::assertSame(
            $saved,
            $this->names(
                $query->matching($this->rows(), [['field' => 'name', 'condition' => 'in', 'value' => $saved]], '')
            )
        );
        self::assertCount(
            2,
            $query->matching($this->rows(), [['field' => 'name', 'condition' => 'nin', 'value' => $saved]], '')
        );
    }

    public function testAFilterTheQueryCannotApplyMatchesNothing(): void
    {
        $query = new AbilityGridQuery();

        self::assertSame([], $query->matching($this->rows(), [
            ['field' => 'aclResources', 'condition' => 'eq', 'value' => 'Magento_Backend::stores'],
        ], ''));
        self::assertSame([], $query->matching($this->rows(), [
            ['field' => 'source', 'condition' => 'gteq', 'value' => 'webapi'],
        ], ''));
    }

    public function testRowsAreOrderedByAFieldWithTheNameBreakingTies(): void
    {
        $query = new AbilityGridQuery();

        self::assertSame(
            ['graphql.query.store_config', 'store.store_repository.get_list', 'store.store_repository.save'],
            $this->names($query->sorted($this->rows(), 'source', false))
        );
        self::assertSame(
            ['store.store_repository.get_list', 'store.store_repository.save', 'graphql.query.store_config'],
            $this->names($query->sorted($this->rows(), 'source', true)),
            'Descending by a field, rows that tie on it still follow the name upwards'
        );
        self::assertSame(
            ['store.store_repository.save', 'store.store_repository.get_list', 'graphql.query.store_config'],
            $this->names($query->sorted($this->rows(), 'name', true))
        );
        self::assertSame(
            ['graphql.query.store_config', 'store.store_repository.get_list', 'store.store_repository.save'],
            $this->names($query->sorted($this->rows(), 'aclResources', false)),
            'A field rows cannot be ordered by orders them by name'
        );
    }

    /**
     * @param list<AbilityRow> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }

    /**
     * @return list<AbilityRow>
     */
    private function rows(): array
    {
        return [
            $this->row('graphql.query.store_config', 'Store config', 'graphql', 'GraphQL', 'read'),
            $this->row('store.store_repository.save', 'Save a store view', 'webapi', 'Magento_Store', 'write'),
            $this->row('store.store_repository.get_list', 'Find store views', 'webapi', 'Magento_Store', 'read'),
        ];
    }

    /**
     * @param string $name
     * @param string $title
     * @param string $source
     * @param string $category
     * @param string $access
     * @return AbilityRow
     */
    private function row(string $name, string $title, string $source, string $category, string $access): array
    {
        return [
            'name' => $name,
            'title' => $title,
            'source' => $source,
            'category' => $category,
            'access' => $access,
            'aclResources' => ['Magento_Backend::stores'],
            'exposure' => 'exposed',
            'reason' => '',
            'deniedHere' => false,
            'confirmHere' => false,
            'sections' => ['Magento_Backend::stores'],
            'section' => 'Magento_Backend::stores',
            'personalData' => 'hidden',
        ];
    }
}
