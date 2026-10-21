<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\Webapi\AbilityNamer;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteAbilityBuilder;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeMetadataReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ability a route offers: name, title, description, category, access and ACL, or a logged skip
 */
class RouteAbilityBuilderTest extends TestCase
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

    public function testAnAbilityTakesItsValuesFromTheRouteAndTheMethodDocumentation(): void
    {
        $documentation = "Get product list. Filters by the search criteria.\nThe second line.";
        $ability = $this->builder([self::PRODUCTS . '::getList' => $documentation])
            ->build($this->route('GET', '/V1/products', self::PRODUCTS, 'getList'), 'webapi');

        self::assertInstanceOf(AbilityInterface::class, $ability);
        self::assertSame('catalog.product_repository.get_list', $ability->getName());
        self::assertSame('Get product list', $ability->getTitle());
        self::assertSame($documentation, $ability->getDescription());
        self::assertSame('Magento_Catalog', $ability->getCategory());
        self::assertSame('webapi', $ability->getSource());
        self::assertSame('GET /V1/products', $ability->getSourceReference());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['Magento_Catalog::products'], $ability->getAclResources());
    }

    public function testATitleWithoutAFullStopIsTheFirstLine(): void
    {
        $ability = $this->builder([self::PRODUCTS . '::get' => "Get info about product by product SKU\n\nMore."])
            ->build($this->route('GET', '/V1/products/:sku', self::PRODUCTS, 'get'), 'webapi');

        self::assertInstanceOf(AbilityInterface::class, $ability);
        self::assertSame('Get info about product by product SKU', $ability->getTitle());
    }

    public function testWithoutDocumentationTheTitleIsTheMethodInWords(): void
    {
        $ability = $this->builder([])
            ->build($this->route('DELETE', '/V1/products/:sku', self::PRODUCTS, 'deleteById'), 'webapi');

        self::assertInstanceOf(AbilityInterface::class, $ability);
        self::assertSame('Delete by id', $ability->getTitle());
        self::assertSame('', $ability->getDescription());
    }

    /**
     * @return array<string, array{string, AbilityAccess}>
     */
    public static function accesses(): array
    {
        return [
            'GET reads' => ['GET', AbilityAccess::Read],
            'POST writes' => ['POST', AbilityAccess::Write],
            'PUT writes' => ['PUT', AbilityAccess::Write],
            'DELETE deletes' => ['DELETE', AbilityAccess::Delete],
        ];
    }

    #[DataProvider('accesses')]
    public function testTheAccessFollowsTheHttpMethod(string $httpMethod, AbilityAccess $access): void
    {
        $route = $this->route($httpMethod, '/V1/products', self::PRODUCTS, 'save');
        $ability = $this->builder([])->build($route, 'webapi');

        self::assertInstanceOf(AbilityInterface::class, $ability);
        self::assertSame($access, $ability->getAccess());
    }

    public function testAnAccessOverrideReplacesTheAccessOfTheHttpMethod(): void
    {
        $builder = $this->builder([], ['catalog.product_repository.get_list' => 'write']);

        $overridden = $builder->build($this->route('GET', '/V1/products', self::PRODUCTS, 'getList'), 'webapi');
        $untouched = $builder->build($this->route('GET', '/V1/products/:sku', self::PRODUCTS, 'get'), 'webapi');

        self::assertSame(AbilityAccess::Write, $overridden?->getAccess());
        self::assertSame(AbilityAccess::Read, $untouched?->getAccess());
        self::assertSame([], $this->logger->records);
    }

    public function testAnAccessOverrideCanAlsoMarkAWriteRouteAsRead(): void
    {
        $ability = $this->builder([], ['catalog.product_repository.save' => 'read'])
            ->build($this->route('POST', '/V1/products', self::PRODUCTS, 'save'), 'webapi');

        self::assertSame(AbilityAccess::Read, $ability?->getAccess());
    }

    public function testAnAccessOverrideThatIsNoAccessDropsTheAbility(): void
    {
        $ability = $this->builder([], ['catalog.product_repository.get_list' => 'readwrite'])
            ->build($this->route('GET', '/V1/products', self::PRODUCTS, 'getList'), 'webapi');

        self::assertNull($ability);
        self::assertStringContainsString('"readwrite"', $this->logger->messages('warning')[0]);
    }

    public function testAnotherHttpMethodIsLoggedAndSkipped(): void
    {
        $ability = $this->builder([])->build($this->route('PATCH', '/V1/products', self::PRODUCTS, 'save'), 'webapi');

        self::assertNull($ability);
        self::assertStringContainsString('PATCH /V1/products', $this->logger->messages('warning')[0]);
    }

    public function testAClassThatCannotBeNamedIsLoggedAndSkipped(): void
    {
        $ability = $this->builder([])
            ->build($this->route('GET', '/V1/orders', 'Acme\Shop\Model\OrderRepository', 'get'), 'webapi');

        self::assertNull($ability);
        self::assertStringContainsString('Acme\Shop\Model\OrderRepository', $this->logger->messages('warning')[0]);
    }

    public function testANameTheAbilityRefusesIsLoggedAndSkipped(): void
    {
        $ability = $this->builder([])
            ->build($this->route('GET', '/V1/orders', 'Acme\Shop\Api\Order_Interface', 'get'), 'webapi');

        self::assertNull($ability);
        self::assertStringContainsString('acme_shop.order_.get', $this->logger->messages('warning')[0]);
    }

    /**
     * @param array<string, string> $documentation Documentation by `<class>::<method>`
     * @param array<string, string> $accessOverrides
     * @return RouteAbilityBuilder
     */
    private function builder(array $documentation, array $accessOverrides = []): RouteAbilityBuilder
    {
        $methods = [];
        foreach ($documentation as $method => $text) {
            $methods[$method] = ['documentation' => $text, 'parameters' => []];
        }

        return new RouteAbilityBuilder(
            new AbilityNamer(),
            new FakeMetadataReader($methods),
            new AbilityInterfaceFactory(new ConstructingObjectManager([AbilityInterface::class => Ability::class])),
            $this->logger,
            $accessOverrides
        );
    }

    /**
     * @param string $httpMethod
     * @param string $url
     * @param string $class
     * @param string $method
     * @return Route
     */
    private function route(string $httpMethod, string $url, string $class, string $method): Route
    {
        return new Route($url, $httpMethod, $class, $method, ['Magento_Catalog::products']);
    }
}
