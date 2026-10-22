<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\TypeDescriber;
use Hryvinskyi\McpServer\Test\Unit\Fake\GraphQlSchemaKit as Kit;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TypeDescriberTest extends TestCase
{
    public function testAnObjectTypeListsItsFieldsWithTypesAndArguments(): void
    {
        $products = Kit::objectType('Products', [
            Kit::field('items', Kit::listOf(Kit::named('INTERFACE', 'ProductInterface')), [], 'The products.'),
            Kit::field('total_count', Kit::scalar('Int')),
            Kit::field('page', Kit::nonNull(Kit::scalar('Int')), [
                Kit::input('size', Kit::nonNull(Kit::scalar('Int')), '20', 'Page size.'),
            ], '', 'Use page_info'),
        ]);

        self::assertSame(
            [
                'name' => 'Products',
                'kind' => 'OBJECT',
                'fields' => [
                    [
                        'name' => 'items',
                        'type' => '[ProductInterface]',
                        'description' => 'The products.',
                        'args' => [],
                    ],
                    ['name' => 'total_count', 'type' => 'Int', 'args' => []],
                    [
                        'name' => 'page',
                        'type' => 'Int!',
                        'args' => [
                            ['name' => 'size', 'type' => 'Int!', 'defaultValue' => '20', 'description' => 'Page size.'],
                        ],
                        'deprecated' => 'Use page_info',
                    ],
                ],
            ],
            $this->describer()->describe($this->schema([$products]), ['name' => 'Products'])
        );
    }

    public function testAnInputObjectListsItsInputFieldsAndAnEnumItsValues(): void
    {
        $schema = $this->schema([
            Kit::inputType('FilterInput', [Kit::input('eq', Kit::scalar('String'))], 'Filters.'),
            Kit::enumType('SortEnum', ['ASC', 'DESC']),
        ]);

        self::assertSame(
            [
                'name' => 'FilterInput',
                'kind' => 'INPUT_OBJECT',
                'description' => 'Filters.',
                'inputFields' => [['name' => 'eq', 'type' => 'String']],
            ],
            $this->describer()->describe($schema, ['name' => 'FilterInput'])
        );
        self::assertSame(
            ['name' => 'SortEnum', 'kind' => 'ENUM', 'enumValues' => [['name' => 'ASC'], ['name' => 'DESC']]],
            $this->describer()->describe($schema, ['name' => ' SortEnum '])
        );
    }

    public function testAnInterfaceListsTheTypesItStandsFor(): void
    {
        $interface = Kit::objectType('ProductInterface', [Kit::field('sku', Kit::scalar('String'))]);
        $interface['kind'] = 'INTERFACE';
        $interface['possibleTypes'] = [Kit::named('OBJECT', 'SimpleProduct'), Kit::named('OBJECT', 'VirtualProduct')];

        $description = $this->describer()->describe($this->schema([$interface]), ['name' => 'ProductInterface']);

        self::assertSame(['SimpleProduct', 'VirtualProduct'], $description['possibleTypes'] ?? null);
    }

    public function testAnUnknownTypeIsRefusedByName(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The GraphQL schema has no type named "Nope".');

        $this->describer()->describe($this->schema([]), ['name' => 'Nope']);
    }

    public function testAMissingNameIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Name the GraphQL type to describe');

        $this->describer()->describe($this->schema([]), ['name' => ['Products']]);
    }

    public function testItsInputSchemaRequiresTheName(): void
    {
        self::assertSame(['name'], $this->describer()->inputSchema()['required'] ?? null);
    }

    /**
     * @param list<array<string,mixed>> $types
     * @return IntrospectedSchema
     */
    private function schema(array $types): IntrospectedSchema
    {
        return Kit::schema([Kit::field('products', Kit::named('OBJECT', 'Products'))], [], $types);
    }

    /**
     * @return TypeDescriber
     */
    private function describer(): TypeDescriber
    {
        return new TypeDescriber(new NodeReader());
    }
}
