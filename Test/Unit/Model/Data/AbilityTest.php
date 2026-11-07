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
            'store.store_repository.get_list',
            'Get store view list',
            'Magento_Store',
            'webapi',
            'GET /V1/store/storeViews',
            AbilityAccess::Read,
            ['Magento_Backend::stores'],
            'Get store view list matching the search criteria.'
        );

        self::assertSame('store.store_repository.get_list', $ability->getName());
        self::assertSame('Get store view list', $ability->getTitle());
        self::assertSame('Get store view list matching the search criteria.', $ability->getDescription());
        self::assertSame('Magento_Store', $ability->getCategory());
        self::assertSame('webapi', $ability->getSource());
        self::assertSame('GET /V1/store/storeViews', $ability->getSourceReference());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['Magento_Backend::stores'], $ability->getAclResources());
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
            'magento module' => ['store.store_repository.get_list'],
            'vendor prefix' => ['acme_shop_core.order_repository.get'],
            'digits' => ['acme_geo.country_information.get_countries_info'],
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
            'two parts' => ['store.store_repository'],
            'four parts' => ['store.store.repository.get'],
            'uppercase' => ['Store.store_repository.get_list'],
            'camel case' => ['store.storeRepository.getList'],
            'leading underscore' => ['_store.store_repository.get'],
            'trailing underscore' => ['store.store_repository.get_'],
            'double underscore' => ['store.store__repository.get'],
            'empty part' => ['store..get'],
            'hyphen' => ['store.store-repository.get'],
            'space' => ['store.store repository.get'],
            'trailing newline' => ["store.store_repository.get\n"],
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
            'title' => [' ', 'Magento_Store', 'webapi', 'GET /V1/store/storeViews'],
            'category' => ['Get', '', 'webapi', 'GET /V1/store/storeViews'],
            'source' => ['Get', 'Magento_Store', "\t", 'GET /V1/store/storeViews'],
            'source reference' => ['Get', 'Magento_Store', 'webapi', ''],
        ];
    }

    #[DataProvider('blankFields')]
    public function testRefusesABlankField(string $title, string $category, string $source, string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ability('store.store_repository.get', $title, $category, $source, $reference, AbilityAccess::Read);
    }

    public function testRefusesABlankAclResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Ability(
            'store.store_repository.get',
            'Get',
            'Magento_Store',
            'webapi',
            'GET /V1/store/storeViews/:id',
            AbilityAccess::Read,
            ['Magento_Backend::stores', ' ']
        );
    }

    /**
     * @param string $name
     * @return Ability
     */
    private function ability(string $name): Ability
    {
        return new Ability($name, 'Title', 'Magento_Store', 'webapi', 'GET /V1/x', AbilityAccess::Read);
    }
}
