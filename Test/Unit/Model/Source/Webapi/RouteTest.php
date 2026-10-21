<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi;

use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use PHPUnit\Framework\TestCase;

/**
 * A route's path parameters, and which of them name the record an update route saves
 */
class RouteTest extends TestCase
{
    private const ORDERS = 'Acme\Shop\Api\OrderRepositoryInterface';

    public function testThePathParametersAreTheColonSegmentsInOrder(): void
    {
        $route = new Route('/V1/orders/:parent_id/items/:itemId', 'GET', self::ORDERS, 'getItem');

        self::assertSame(['parent_id', 'itemId'], $route->getPathParameterNames());
        self::assertSame([], (new Route('/V1/orders', 'GET', self::ORDERS, 'getList'))->getPathParameterNames());
    }

    public function testAnUpdateRouteNamesItsRecordByAPathParameterTheMethodDoesNotTake(): void
    {
        $route = new Route('/V1/orders/:parent_id', 'PUT', self::ORDERS, 'save');

        self::assertSame(['parent_id'], $route->pathIdNames(['entity']));
    }

    public function testAPathParameterTheMethodTakesIsNoRecordIdWhateverItsSpelling(): void
    {
        $route = new Route('/V1/orders/:parent_id/items/:sku', 'PUT', self::ORDERS, 'saveItem');

        self::assertSame([], $route->pathIdNames(['parentId', 'sku', 'item']));
        self::assertSame(['sku'], $route->pathIdNames(['parentId', 'item']));
    }

    public function testOnlyAPutRouteHasRecordIds(): void
    {
        foreach (['GET', 'POST', 'DELETE'] as $httpMethod) {
            $route = new Route('/V1/orders/:parent_id', $httpMethod, self::ORDERS, 'save');

            self::assertSame([], $route->pathIdNames(['entity']), $httpMethod);
        }
    }

    public function testARouteIsNotSecureUnlessDeclaredSo(): void
    {
        self::assertFalse((new Route('/V1/orders', 'GET', self::ORDERS, 'getList'))->isSecure());
        self::assertTrue((new Route('/V1/orders', 'GET', self::ORDERS, 'getList', [], null, true))->isSecure());
    }
}
