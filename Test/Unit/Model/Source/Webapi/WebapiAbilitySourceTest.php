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
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteFactory;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteIndex;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiInvoker;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\CountingSchemaBuilder;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAuthorization;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeMetadataReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeWebapiConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Authorization;
use PHPUnit\Framework\TestCase;

/**
 * The source over its routes: one ability per name, listed once per request, and ACL and schema per route
 */
class WebapiAbilitySourceTest extends TestCase
{
    private const PRODUCTS = 'Magento\Catalog\Api\ProductRepositoryInterface';

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var CountingSchemaBuilder
     */
    private CountingSchemaBuilder $schemaBuilder;

    /**
     * @var FakeMetadataReader
     */
    private FakeMetadataReader $metadata;

    /**
     * @var FakeAuthorization
     */
    private FakeAuthorization $acl;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->schemaBuilder = new CountingSchemaBuilder();
        $this->metadata = new FakeMetadataReader();
        $this->acl = new FakeAuthorization(['Magento_Catalog::products', 'anonymous']);
    }

    public function testEveryRouteBecomesAnAbilityOfTheSource(): void
    {
        $source = $this->source([
            '/V1/products' => [
                'GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList'),
                'POST' => FakeWebapiConfig::route(self::PRODUCTS, 'save'),
            ],
        ]);

        $abilities = $this->listed($source);

        self::assertSame('webapi', $source->getCode());
        self::assertSame(
            ['catalog.product_repository.get_list', 'catalog.product_repository.save'],
            array_map(static fn (AbilityInterface $ability): string => $ability->getName(), $abilities)
        );
        self::assertSame(AbilityAccess::Write, $abilities[1]->getAccess());
    }

    public function testANameAlreadyTakenIsSkippedAndTheFirstRouteInUrlOrderKeepsIt(): void
    {
        $source = $this->source([
            '/V1/z/modules' => ['GET' => FakeWebapiConfig::route(
                'Magento\Backend\Api\ModuleServiceInterface',
                'getModules'
            )],
            '/V1/a/modules' => ['GET' => FakeWebapiConfig::route(
                'Magento\Backend\Service\V1\ModuleServiceInterface',
                'getModules'
            )],
        ]);

        $abilities = $this->listed($source);

        self::assertCount(1, $abilities);
        self::assertSame('GET /V1/a/modules', $abilities[0]->getSourceReference());
        self::assertStringContainsString('GET /V1/z/modules', $this->logger->messages('warning')[0]);
    }

    public function testTheAbilitiesAreListedOncePerRequestAndAgainAfterAReset(): void
    {
        $source = $this->source([
            '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList')],
        ]);

        $ability = $this->listed($source)[0];
        $this->listed($source);
        $source->inputSchema($ability);
        $source->isAllowed($ability);
        self::assertSame(1, $this->metadata->methodReads);

        $source->_resetState();
        $this->listed($source);
        self::assertSame(2, $this->metadata->methodReads);
    }

    public function testTheAclAsksForEveryResourceOfTheRoute(): void
    {
        $source = $this->source([
            '/V1/products' => ['GET' => FakeWebapiConfig::route(
                self::PRODUCTS,
                'getList',
                ['Magento_Catalog::products', 'Magento_Backend::admin']
            )],
            '/V1/directory/countries' => ['GET' => FakeWebapiConfig::route(
                'Magento\Directory\Api\CountryInformationAcquirerInterface',
                'getCountriesInfo',
                ['anonymous']
            )],
        ]);
        [$countries, $products] = $this->listed($source);

        self::assertTrue($source->isAllowed($countries));
        self::assertFalse($source->isAllowed($products));
        self::assertSame(['anonymous', 'Magento_Catalog::products', 'Magento_Backend::admin'], $this->acl->asked);
    }

    public function testTheSchemaComesFromTheBuilder(): void
    {
        $source = $this->source([
            '/V1/products' => ['GET' => FakeWebapiConfig::route(self::PRODUCTS, 'getList')],
        ]);

        self::assertSame(['type' => 'object'], $source->inputSchema($this->listed($source)[0]));
        self::assertSame(1, $this->schemaBuilder->builds);
    }

    public function testAnAbilityTheSourceDoesNotOfferIsRefused(): void
    {
        $source = $this->source([]);

        $this->expectException(NoSuchEntityException::class);

        $source->isAllowed(new Ability(
            'catalog.product_repository.get_list',
            'Get list',
            'Magento_Catalog',
            'webapi',
            'GET /V1/products',
            AbilityAccess::Read
        ));
    }

    /**
     * @param WebapiAbilitySource $source
     * @return list<AbilityInterface>
     */
    private function listed(WebapiAbilitySource $source): array
    {
        $abilities = [];
        foreach ($source->abilities() as $ability) {
            $abilities[] = $ability;
        }

        return $abilities;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $routes
     * @return WebapiAbilitySource
     */
    private function source(array $routes): WebapiAbilitySource
    {
        return new WebapiAbilitySource(
            new RouteIndex(
                new FakeWebapiConfig($routes),
                new RouteFactory(new ConstructingObjectManager([Route::class => Route::class])),
                $this->logger
            ),
            new RouteAbilityBuilder(
                new AbilityNamer(),
                $this->metadata,
                new AbilityInterfaceFactory(new ConstructingObjectManager([AbilityInterface::class => Ability::class])),
                $this->logger
            ),
            $this->schemaBuilder,
            $this->createStub(WebapiInvoker::class),
            new Authorization($this->acl),
            $this->logger
        );
    }
}
