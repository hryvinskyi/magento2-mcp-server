<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi;

use Hryvinskyi\McpServer\Model\Source\Webapi\AbilityNamer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ability names and categories from service interfaces and methods
 */
class AbilityNamerTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nameable(): array
    {
        return [
            'a core repository' => [
                'Magento\Catalog\Api\ProductRepositoryInterface',
                'getList',
                'catalog.product_repository.get_list',
            ],
            'another vendor, its module in two words' => [
                'Acme\ShopCore\Api\OrderRepositoryInterface',
                'get',
                'acme_shop_core.order_repository.get',
            ],
            'a sub-namespace under Api' => [
                'Acme\Shop\Api\Order\ItemRepositoryInterface',
                'getList',
                'acme_shop.order_item_repository.get_list',
            ],
            'the Service form with a version segment' => [
                'Magento\Backend\Service\V1\ModuleServiceInterface',
                'getModules',
                'backend.module_service.get_modules',
            ],
            'an acronym in the method' => [
                'Magento\Cms\Api\BlockRepositoryInterface',
                'getCMSBlock',
                'cms.block_repository.get_cms_block',
            ],
            'a leading backslash' => [
                '\Magento\Directory\Api\CountryInformationAcquirerInterface',
                'getCountriesInfo',
                'directory.country_information_acquirer.get_countries_info',
            ],
            'Api wins over a Service segment after it' => [
                'Acme\Shop\Api\Service\PriceInterface',
                'get',
                'acme_shop.service_price.get',
            ],
            'a class without the Interface suffix' => [
                'Acme\Shop\Api\PriceCalculator',
                'calculate',
                'acme_shop.price_calculator.calculate',
            ],
        ];
    }

    #[DataProvider('nameable')]
    public function testNamesAServiceMethod(string $class, string $method, string $expected): void
    {
        self::assertSame($expected, (new AbilityNamer())->nameOf($class, $method));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unnameable(): array
    {
        return [
            'neither Api nor Service' => ['Acme\Shop\Model\OrderRepository', 'get'],
            'Api in the vendor position' => ['Api\Shop\OrderInterface', 'get'],
            'nothing after Api' => ['Acme\Shop\Api', 'get'],
            'only a version after Service' => ['Acme\Shop\Service\V1', 'get'],
            'only the suffix after Api' => ['Acme\Shop\Api\Interface', 'get'],
            'no method' => ['Magento\Catalog\Api\ProductRepositoryInterface', ''],
            'a global class' => ['ProductRepositoryInterface', 'get'],
        ];
    }

    #[DataProvider('unnameable')]
    public function testAClassItCannotNameGivesNull(string $class, string $method): void
    {
        self::assertNull((new AbilityNamer())->nameOf($class, $method));
    }

    public function testTheCategoryIsTheModuleOfTheClass(): void
    {
        $namer = new AbilityNamer();

        self::assertSame('Magento_Catalog', $namer->categoryOf('Magento\Catalog\Api\ProductRepositoryInterface'));
        self::assertSame('Acme_ShopCore', $namer->categoryOf('\Acme\ShopCore\Api\OrderRepositoryInterface'));
        self::assertNull($namer->categoryOf('Acme\Shop'));
    }

    public function testSnakeCaseSplitsCamelCaseAndAcronyms(): void
    {
        $namer = new AbilityNamer();

        self::assertSame('get_cms_block', $namer->snake('getCMSBlock'));
        self::assertSame('product_repository', $namer->snake('ProductRepository'));
        self::assertSame('get_v2_data', $namer->snake('getV2Data'));
        self::assertSame('oauth2_token', $namer->snake('Oauth2Token'));
        self::assertSame('save', $namer->snake('save'));
    }
}
