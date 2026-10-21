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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AbilityTest extends TestCase
{
    public function testKeepsWhatItWasGiven(): void
    {
        $ability = new Ability(
            'catalog.product_repository.get_list',
            'Get product list',
            'Magento_Catalog',
            'webapi',
            'GET /V1/products',
            AbilityAccess::Read,
            ['Magento_Catalog::products'],
            'Get product list matching the search criteria.'
        );

        self::assertSame('catalog.product_repository.get_list', $ability->getName());
        self::assertSame('Get product list', $ability->getTitle());
        self::assertSame('Get product list matching the search criteria.', $ability->getDescription());
        self::assertSame('Magento_Catalog', $ability->getCategory());
        self::assertSame('webapi', $ability->getSource());
        self::assertSame('GET /V1/products', $ability->getSourceReference());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['Magento_Catalog::products'], $ability->getAclResources());
    }

    public function testDescriptionAndResourcesMayBeEmpty(): void
    {
        $ability = new Ability('acme_shop.order.get', 'Get', 'Acme_Shop', 'pool', 'acme', AbilityAccess::Write);

        self::assertSame('', $ability->getDescription());
        self::assertSame([], $ability->getAclResources());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wellFormedNames(): array
    {
        return [
            'magento module' => ['catalog.product_repository.get_list'],
            'vendor prefix' => ['acme_shop_core.order_repository.get'],
            'digits' => ['directory.country_information_acquirer.get_countries_info'],
            'digit words' => ['oauth2.v2_token.get'],
            'one letter parts' => ['a.b.c'],
        ];
    }

    #[DataProvider('wellFormedNames')]
    public function testAcceptsAWellFormedName(string $name): void
    {
        self::assertSame($name, $this->ability($name)->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenNames(): array
    {
        return [
            'empty' => [''],
            'two parts' => ['catalog.product_repository'],
            'four parts' => ['catalog.product.repository.get'],
            'uppercase' => ['Catalog.product_repository.get_list'],
            'camel case' => ['catalog.productRepository.getList'],
            'leading underscore' => ['_catalog.product_repository.get'],
            'trailing underscore' => ['catalog.product_repository.get_'],
            'double underscore' => ['catalog.product__repository.get'],
            'empty part' => ['catalog..get'],
            'hyphen' => ['catalog.product-repository.get'],
            'space' => ['catalog.product repository.get'],
            'trailing newline' => ["catalog.product_repository.get\n"],
            'too long' => ['a.b.' . str_repeat('c', 252)],
        ];
    }

    #[DataProvider('brokenNames')]
    public function testRefusesANameThatBreaksTheFormat(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ability($name);
    }

    public function testTheLongestNameIsAccepted(): void
    {
        $name = 'a.b.' . str_repeat('c', 251);

        self::assertSame(255, strlen($this->ability($name)->getName()));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function blankFields(): array
    {
        return [
            'title' => [' ', 'Magento_Catalog', 'webapi', 'GET /V1/products'],
            'category' => ['Get', '', 'webapi', 'GET /V1/products'],
            'source' => ['Get', 'Magento_Catalog', "\t", 'GET /V1/products'],
            'source reference' => ['Get', 'Magento_Catalog', 'webapi', ''],
        ];
    }

    #[DataProvider('blankFields')]
    public function testRefusesABlankField(string $title, string $category, string $source, string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ability('catalog.product_repository.get', $title, $category, $source, $reference, AbilityAccess::Read);
    }

    public function testRefusesABlankAclResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ability(
            'catalog.product_repository.get',
            'Get',
            'Magento_Catalog',
            'webapi',
            'GET /V1/products/:sku',
            AbilityAccess::Read,
            ['Magento_Catalog::products', ' ']
        );
    }

    /**
     * @param string $name
     * @return Ability
     */
    private function ability(string $name): Ability
    {
        return new Ability($name, 'Title', 'Magento_Catalog', 'webapi', 'GET /V1/x', AbilityAccess::Read);
    }
}
