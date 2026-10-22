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
            ['catalog.product_repository.save'],
            $this->names($query->matching($rows, [
                ['field' => 'source', 'condition' => 'eq', 'value' => 'webapi'],
                ['field' => 'access', 'condition' => 'eq', 'value' => 'write'],
            ], ''))
        );
        self::assertSame(
            ['catalog.product_repository.save', 'catalog.product_repository.get_list'],
            $this->names($query->matching($rows, [], 'PRODUCT catalog')),
            'Matching keeps the rows in the order they came'
        );
        self::assertSame(
            ['graphql.query.products'],
            $this->names(
                $query->matching($rows, [['field' => 'name', 'condition' => 'like', 'value' => '%Query%']], '')
            )
        );
    }

    public function testSelectionsByListAreKeptOrLeftOut(): void
    {
        $query = new AbilityGridQuery();
        $saved = ['catalog.product_repository.save'];

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
            ['field' => 'aclResources', 'condition' => 'eq', 'value' => 'Magento_Catalog::products'],
        ], ''));
        self::assertSame([], $query->matching($this->rows(), [
            ['field' => 'source', 'condition' => 'gteq', 'value' => 'webapi'],
        ], ''));
    }

    public function testRowsAreOrderedByAFieldWithTheNameBreakingTies(): void
    {
        $query = new AbilityGridQuery();

        self::assertSame(
            ['graphql.query.products', 'catalog.product_repository.get_list', 'catalog.product_repository.save'],
            $this->names($query->sorted($this->rows(), 'source', false))
        );
        self::assertSame(
            ['graphql.query.products', 'catalog.product_repository.save', 'catalog.product_repository.get_list'],
            $this->names($query->sorted($this->rows(), 'name', true))
        );
        self::assertSame(
            ['catalog.product_repository.get_list', 'catalog.product_repository.save', 'graphql.query.products'],
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
            $this->row('graphql.query.products', 'Products', 'graphql', 'GraphQL', 'read'),
            $this->row('catalog.product_repository.save', 'Save a product', 'webapi', 'Magento_Catalog', 'write'),
            $this->row('catalog.product_repository.get_list', 'Find products', 'webapi', 'Magento_Catalog', 'read'),
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
            'aclResources' => ['Magento_Catalog::products'],
            'exposure' => 'exposed',
            'reason' => '',
            'deniedHere' => false,
            'confirmHere' => false,
        ];
    }
}
