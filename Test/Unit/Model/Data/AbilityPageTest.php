<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use PHPUnit\Framework\TestCase;

class AbilityPageTest extends TestCase
{
    public function testKeepsItsItemsAndTotal(): void
    {
        $ability = new Ability('a.b.c', 'Title', 'Magento_Catalog', 'webapi', 'GET /V1/x', AbilityAccess::Read);
        $page = new AbilityPage(40, [$ability]);

        self::assertSame(40, $page->getTotal());
        self::assertSame([$ability], $page->getItems());
    }

    public function testAnEmptyPageMayFollowTheLastMatch(): void
    {
        $page = new AbilityPage(3);

        self::assertSame(3, $page->getTotal());
        self::assertSame([], $page->getItems());
    }

    public function testRefusesMoreItemsThanMatched(): void
    {
        $ability = new Ability('a.b.c', 'Title', 'Magento_Catalog', 'webapi', 'GET /V1/x', AbilityAccess::Read);

        $this->expectException(\InvalidArgumentException::class);

        new AbilityPage(1, [$ability, $ability]);
    }

    public function testRefusesANegativeTotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AbilityPage(-1);
    }
}
