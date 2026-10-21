<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi;

use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteFactory;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteIndex;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeWebapiConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Which routes become abilities: one per service method, never a `self` route or an excluded service
 */
class RouteIndexTest extends TestCase
{
    private const PRODUCTS = 'Magento\Catalog\Api\ProductRepositoryInterface';

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testARouteCarriesItsServiceVersionResourcesAsAListAndParameters(): void
    {
        $routes = $this->index([
            '/V1/products' => ['GET' => FakeWebapiConfig::route(
                self::PRODUCTS,
                'getList',
                ['Magento_Catalog::products', 'Magento_Backend::admin'],
                ['storeId' => false],
                50
            )],
        ])->routes();

        self::assertCount(1, $routes);
        $route = $routes[0];
        self::assertSame('/V1/products', $route->getUrl());
        self::assertSame('GET', $route->getHttpMethod());
        self::assertSame(self::PRODUCTS, $route->getServiceClass());
        self::assertSame('getList', $route->getServiceMethod());
        self::assertSame('V1', $route->getVersion());
        self::assertSame('GET /V1/products', $route->getReference());
        self::assertSame(['Magento_Catalog::products', 'Magento_Backend::admin'], $route->getAclResources());
        self::assertSame(50, $route->getInputArraySizeLimit());
        self::assertSame(['storeId' => ['force' => false, 'value' => '%customer_id%']], $route->getParameters());
        self::assertSame([], $route->getForcedParameterNames());
        self::assertFalse($route->isSecure());
    }

    public function testARouteKeepsItsSecureFlag(): void
    {
        $routes = $this->index([
            '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList', secure: true)],
        ])->routes();

        self::assertTrue($routes[0]->isSecure());
    }

    public function testTheRouteWithFewerForcedParametersWins(): void
    {
        $routes = $this->index([
            '/V1/a' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'get', forced: ['sku' => true])],
            '/V1/products/long/path' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'get')],
        ])->routes();

        self::assertSame(['/V1/products/long/path'], $this->urls($routes));
    }

    public function testOnATieTheLowerVersionWins(): void
    {
        $routes = $this->index([
            '/V2/p' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'get')],
            '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'get')],
        ])->routes();

        self::assertSame(['/V1/products'], $this->urls($routes));
    }

    public function testOnATieOfVersionTheShorterUrlWins(): void
    {
        $routes = $this->index([
            '/V1/products/:sku' => ['PUT' => FakeWebapiConfig::route(self::PRODUCTS, 'save')],
            '/V1/products' => ['POST' => FakeWebapiConfig::route(self::PRODUCTS, 'save')],
        ])->routes();

        self::assertCount(1, $routes);
        self::assertSame('POST /V1/products', $routes[0]->getReference());
    }

    public function testRoutesOfDifferentMethodsAreAllKeptAndSortedByUrl(): void
    {
        $routes = $this->index([
            '/V1/products/:sku' => [
                'GET' => FakeWebapiConfig::route(self::PRODUCTS, 'get'),
                'DELETE' => FakeWebapiConfig::route(self::PRODUCTS, 'deleteById'),
            ],
            '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList')],
        ])->routes();

        self::assertSame(
            ['GET /V1/products', 'DELETE /V1/products/:sku', 'GET /V1/products/:sku'],
            array_map(static fn (Route $route): string => $route->getReference(), $routes)
        );
    }

    public function testASelfRouteIsSkippedAndAnAnonymousOneKept(): void
    {
        $customers = 'Magento\Customer\Api\CustomerRepositoryInterface';
        $routes = $this->index([
            '/V1/customers/me' => ['GET' => FakeWebapiConfig::route($customers, 'getById', ['self'])],
            '/V1/directory/countries' => ['GET' => FakeWebapiConfig::route(
                'Magento\Directory\Api\CountryInformationAcquirerInterface',
                'getCountriesInfo',
                ['anonymous']
            )],
        ])->routes();

        self::assertSame(['/V1/directory/countries'], $this->urls($routes));
        self::assertSame(['anonymous'], $routes[0]->getAclResources());
    }

    public function testASelfRouteNeverWinsOverTheAdminRouteOfTheSameMethod(): void
    {
        $customers = 'Magento\Customer\Api\CustomerRepositoryInterface';
        $routes = $this->index([
            '/V1/customers/me' => ['GET' => FakeWebapiConfig::route($customers, 'getById', ['self'])],
            '/V1/customers/:customerId' => ['GET' => FakeWebapiConfig::route(
                $customers,
                'getById',
                ['Magento_Customer::customer']
            )],
        ])->routes();

        self::assertSame(['/V1/customers/:customerId'], $this->urls($routes));
    }

    public function testAnExcludedServiceIsSkipped(): void
    {
        $routes = $this->index(
            [
                '/V1/mcp' => ['POST' => FakeWebapiConfig::route('Acme\Mcp\Api\EndpointInterface', 'handle')],
                '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList')],
            ],
            ['\Acme\Mcp\Api\EndpointInterface']
        )->routes();

        self::assertSame(['/V1/products'], $this->urls($routes));
    }

    public function testAnIncompleteOrUnversionedRouteIsLoggedAndSkipped(): void
    {
        $routes = $this->index([
            '/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList')],
            '/V1/broken' => ['GET' => ['service' => [], 'resources' => []]],
            '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'get')],
        ])->routes();

        self::assertSame(['/V1/products'], $this->urls($routes));
        self::assertCount(2, $this->logger->messages('warning'));
        self::assertStringContainsString('GET /products', $this->logger->messages('warning')[0]);
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $routes
     * @param string[] $excluded
     * @return RouteIndex
     */
    private function index(array $routes, array $excluded = []): RouteIndex
    {
        return new RouteIndex(
            new FakeWebapiConfig($routes),
            new RouteFactory(new ConstructingObjectManager([Route::class => Route::class])),
            $this->logger,
            $excluded
        );
    }

    /**
     * @param list<Route> $routes
     * @return list<string>
     */
    private function urls(array $routes): array
    {
        return array_map(static fn (Route $route): string => $route->getUrl(), $routes);
    }
}
