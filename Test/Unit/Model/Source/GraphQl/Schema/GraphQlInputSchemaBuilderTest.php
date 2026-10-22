<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\GraphQlInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\GraphQlSchemaKit as Kit;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

class GraphQlInputSchemaBuilderTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testScalarsMapToJsonTypesAndAnyOtherScalarTakesAnything(): void
    {
        $field = Kit::field('lookup', Kit::scalar('String'), [
            Kit::input('text', Kit::scalar('String')),
            Kit::input('id', Kit::scalar('ID')),
            Kit::input('count', Kit::scalar('Int')),
            Kit::input('ratio', Kit::scalar('Float')),
            Kit::input('flag', Kit::scalar('Boolean')),
            Kit::input('blob', Kit::scalar('JSON')),
        ]);

        $schema = $this->build(Kit::schema([$field], [], [Kit::scalarType('JSON')]), $field);

        self::assertSame(
            '{"type":"object","properties":{"text":{"type":"string"},"id":{"type":"string"},'
            . '"count":{"type":"integer"},"ratio":{"type":"number"},"flag":{"type":"boolean"},'
            . '"blob":{"description":"A value of the GraphQL scalar JSON."}}}',
            json_encode($schema, JSON_THROW_ON_ERROR)
        );
    }

    public function testAnEnumIsAStringLimitedToItsValues(): void
    {
        $field = Kit::field('sorted', Kit::scalar('String'), [Kit::input('order', Kit::named('ENUM', 'SortEnum'))]);

        $schema = $this->build(Kit::schema([$field], [], [Kit::enumType('SortEnum', ['ASC', 'DESC'])]), $field);

        self::assertSame(['type' => 'string', 'enum' => ['ASC', 'DESC']], $this->property($schema, 'order'));
    }

    public function testAListIsAnArrayOfItsItemType(): void
    {
        $field = Kit::field('blocks', Kit::scalar('String'), [
            Kit::input('identifiers', Kit::nonNull(Kit::listOf(Kit::nonNull(Kit::scalar('String'))))),
        ]);

        $schema = $this->build(Kit::schema([$field]), $field);

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $this->property($schema, 'identifiers'));
        self::assertSame(['identifiers'], $schema['required'] ?? null);
    }

    public function testANonNullArgumentIsRequiredUnlessItHasADefault(): void
    {
        $field = Kit::field('page', Kit::scalar('String'), [
            Kit::input('cart_id', Kit::nonNull(Kit::scalar('String')), null, 'The cart.'),
            Kit::input('pageSize', Kit::nonNull(Kit::scalar('Int')), '20', 'The page size.'),
            Kit::input('search', Kit::scalar('String')),
        ]);

        $schema = $this->build(Kit::schema([$field]), $field);

        self::assertSame(['cart_id'], $schema['required'] ?? null);
        self::assertSame(['type' => 'string', 'description' => 'The cart.'], $this->property($schema, 'cart_id'));
        self::assertSame(
            ['type' => 'integer', 'description' => 'The page size. Defaults to `20`.'],
            $this->property($schema, 'pageSize')
        );
    }

    public function testNestedInputObjectsCarryTheirFieldsAndTheirOwnRequiredFields(): void
    {
        $field = Kit::field('products', Kit::named('OBJECT', 'Products'), [
            Kit::input('filter', Kit::named('INPUT_OBJECT', 'ProductFilter'), null, 'What to find.'),
        ]);
        $types = [
            Kit::objectType('Products', [Kit::field('total_count', Kit::scalar('Int'))]),
            Kit::inputType('ProductFilter', [
                Kit::input('sku', Kit::named('INPUT_OBJECT', 'EqualFilter')),
                Kit::input('name', Kit::nonNull(Kit::scalar('String'))),
            ], 'Filters products.'),
            Kit::inputType('EqualFilter', [
                Kit::input('eq', Kit::scalar('String')),
                Kit::input('in', Kit::listOf(Kit::scalar('String'))),
            ]),
        ];

        $schema = $this->build(Kit::schema([$field], [], $types), $field);

        self::assertSame(
            [
                'type' => 'object',
                'description' => 'What to find.',
                'properties' => [
                    'sku' => [
                        'type' => 'object',
                        'properties' => [
                            'eq' => ['type' => 'string'],
                            'in' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                    'name' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ],
            $this->property($schema, 'filter')
        );
    }

    public function testAnInputObjectThatContainsItselfIsCutOff(): void
    {
        $field = Kit::field('tree', Kit::scalar('String'), [Kit::input('node', Kit::named('INPUT_OBJECT', 'Node'))]);
        $types = [Kit::inputType('Node', [Kit::input('child', Kit::named('INPUT_OBJECT', 'Node'))])];

        $schema = $this->build(Kit::schema([$field], [], $types), $field);

        self::assertSame(
            ['type' => 'object', 'description' => 'Details omitted: the type Node contains itself.'],
            $this->propertiesOf($this->property($schema, 'node'))['child'] ?? null
        );
    }

    public function testInputObjectsNestAtMostSixLevelsDeep(): void
    {
        $types = [];
        for ($level = 1; $level <= 8; $level++) {
            $types[] = Kit::inputType('Level' . $level, [
                Kit::input('next', Kit::named('INPUT_OBJECT', 'Level' . ($level + 1))),
            ]);
        }
        $field = Kit::field('deep', Kit::scalar('String'), [Kit::input('level', Kit::named('INPUT_OBJECT', 'Level1'))]);

        $schema = $this->build(Kit::schema([$field], [], $types), $field);

        $node = $this->property($schema, 'level');
        for ($level = 1; $level < GraphQlInputSchemaBuilder::MAX_DEPTH; $level++) {
            $node = $this->propertiesOf($node)['next'] ?? null;
            self::assertIsArray($node);
            self::assertArrayHasKey('properties', $node);
        }
        self::assertSame(
            [
                'type' => 'object',
                'description' => 'Details omitted: the type Level7 is nested more than 6 levels deep.',
            ],
            $this->propertiesOf($node)['next'] ?? null
        );
    }

    public function testATypeTheSchemaDoesNotDescribeIsLoggedAndTakesAnything(): void
    {
        $field = Kit::field('lookup', Kit::scalar('String'), [
            Kit::input('where', Kit::named('INPUT_OBJECT', 'MissingInput'), null, 'Where to look.'),
            Kit::input('text', Kit::scalar('String')),
        ]);

        $schema = $this->build(Kit::schema([$field]), $field);

        self::assertSame(
            ['description' => 'Where to look. A value of the GraphQL type MissingInput, which the schema does not '
                . 'describe.'],
            $this->property($schema, 'where')
        );
        self::assertSame(['type' => 'string'], $this->property($schema, 'text'));
        self::assertCount(1, $this->logger->messages('warning'));
        self::assertStringContainsString('MissingInput', $this->logger->messages('warning')[0]);
    }

    public function testAFieldWithAnObjectResultRequiresASelection(): void
    {
        $field = Kit::field('countries', Kit::listOf(Kit::named('OBJECT', 'Country')));

        $schema = $this->build(Kit::schema([$field], [], [Kit::objectType('Country', [])]), $field);

        self::assertSame([GraphQlInputSchemaBuilder::SELECTION], $schema['required'] ?? null);
        $selection = $this->property($schema, GraphQlInputSchemaBuilder::SELECTION);
        self::assertSame('string', $selection['type'] ?? null);
        $description = $selection['description'] ?? null;
        self::assertIsString($description);
        self::assertStringContainsString('[Country]', $description);
    }

    public function testAFieldWithAScalarOrEnumResultTakesNoSelectionAndAnEmptyObjectStaysAnObject(): void
    {
        $scalar = Kit::field('createEmptyCart', Kit::nonNull(Kit::scalar('String')));
        $enum = Kit::field('mode', Kit::named('ENUM', 'Mode'));
        $schema = Kit::schema([$scalar, $enum], [], [Kit::enumType('Mode', ['ON'])]);

        self::assertSame('{"type":"object","properties":{}}', json_encode($this->build($schema, $scalar)));
        self::assertSame('{"type":"object","properties":{}}', json_encode($this->build($schema, $enum)));
    }

    /**
     * @param IntrospectedSchema $schema
     * @param array<string,mixed> $field
     * @return array<string, mixed>
     */
    private function build(IntrospectedSchema $schema, array $field): array
    {
        $nodes = new NodeReader();
        $typed = $nodes->fieldsOf(['fields' => [$field]])[0] ?? null;
        self::assertNotNull($typed);

        return (new GraphQlInputSchemaBuilder($nodes, $this->logger))->build($schema, $typed);
    }

    /**
     * @param array<string,mixed> $schema
     * @param string $name
     * @return array<mixed>
     */
    private function property(array $schema, string $name): array
    {
        $property = $this->propertiesOf($schema)[$name] ?? null;
        self::assertIsArray($property);

        return $property;
    }

    /**
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function propertiesOf(array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);

        return $properties;
    }
}
