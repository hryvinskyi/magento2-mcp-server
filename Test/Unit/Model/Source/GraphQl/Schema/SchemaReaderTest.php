<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchemaFactory;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\SchemaReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading an introspection `__schema` into the schema value, and refusing one that describes no schema
 */
class SchemaReaderTest extends TestCase
{
    public function testReadsTheRootTypeNamesAndTheTypesByName(): void
    {
        $schema = self::reader()->read(self::schemaNode());

        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertSame('RootQuery', $schema->getQueryTypeName());
        self::assertSame('RootMutation', $schema->getMutationTypeName());
        self::assertSame(['RootQuery', 'RootMutation', 'Country', 'String'], array_keys($schema->getTypes()));
        $country = $schema->getType('Country');
        self::assertIsArray($country);
        self::assertSame('OBJECT', $country['kind']);
        self::assertNull($schema->getType('Missing'));
    }

    public function testASchemaWithoutMutationsHasNoMutationTypeName(): void
    {
        $node = self::schemaNode();
        $node['mutationType'] = null;

        $schema = self::reader()->read($node);

        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertSame('', $schema->getMutationTypeName());
    }

    public function testKeysThatWouldBuildObjectsStayPlainData(): void
    {
        $node = self::schemaNode();
        $node['types'][] = [
            'kind' => 'OBJECT',
            'name' => 'Trap',
            'instance' => 'Magento\Framework\DataObject',
            'argument' => 'db',
        ];

        $schema = self::reader()->read($node);

        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertSame(
            ['kind' => 'OBJECT', 'name' => 'Trap', 'instance' => 'Magento\Framework\DataObject', 'argument' => 'db'],
            $schema->getType('Trap')
        );
    }

    public function testTypesWithoutANameOrKindAreLeftOutAndTheFirstOfADuplicateIsKept(): void
    {
        $node = self::schemaNode();
        $node['types'][] = ['kind' => 'OBJECT'];
        $node['types'][] = ['name' => 'NoKind'];
        $node['types'][] = 'not a type';
        $node['types'][] = ['kind' => 'SCALAR', 'name' => 'Country'];

        $schema = self::reader()->read($node);

        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertSame(['RootQuery', 'RootMutation', 'Country', 'String'], array_keys($schema->getTypes()));
        self::assertSame('OBJECT', ($schema->getType('Country') ?? [])['kind'] ?? null);
    }

    /**
     * @param array<mixed> $node
     */
    #[DataProvider('noSchemas')]
    public function testANodeThatDescribesNoSchemaGivesNull(array $node): void
    {
        self::assertNull(self::reader()->read($node));
    }

    /**
     * @return array<string,array{array<mixed>}>
     */
    public static function noSchemas(): array
    {
        $node = self::schemaNode();

        return [
            'empty' => [[]],
            'no query type' => [array_diff_key($node, ['queryType' => true])],
            'unnamed query type' => [['queryType' => ['name' => null]] + $node],
            'query type not among the types' => [['queryType' => ['name' => 'Elsewhere']] + $node],
            'mutation type not among the types' => [['mutationType' => ['name' => 'Elsewhere']] + $node],
            'types not a list' => [['types' => 'many'] + $node],
        ];
    }

    public function testTheIntrospectionShapeReadsBackToTheSameSchema(): void
    {
        $schema = self::reader()->read(self::schemaNode());
        self::assertInstanceOf(IntrospectedSchema::class, $schema);

        $again = self::reader()->read($schema->toIntrospection());

        self::assertInstanceOf(IntrospectedSchema::class, $again);
        self::assertSame($schema->getTypes(), $again->getTypes());
        self::assertSame($schema->getQueryTypeName(), $again->getQueryTypeName());
        self::assertSame($schema->getMutationTypeName(), $again->getMutationTypeName());
    }

    public function testTheValueRefusesRootTypesItDoesNotHold(): void
    {
        $empty = new IntrospectedSchema();

        $this->expectException(\InvalidArgumentException::class);
        $empty->withSchema('Query', '', ['Mutation' => ['kind' => 'OBJECT', 'name' => 'Mutation']]);
    }

    public function testFillingTheValueLeavesTheOriginalEmpty(): void
    {
        $empty = new IntrospectedSchema();

        $filled = $empty->withSchema('Query', '', ['Query' => ['kind' => 'OBJECT', 'name' => 'Query']]);

        self::assertSame('Query', $filled->getQueryTypeName());
        self::assertSame('', $empty->getQueryTypeName());
        self::assertSame([], $empty->getTypes());
    }

    /**
     * The reader with the generated schema factory
     *
     * @return SchemaReader
     */
    public static function reader(): SchemaReader
    {
        return new SchemaReader(
            new IntrospectedSchemaFactory(
                new ConstructingObjectManager([IntrospectedSchema::class => IntrospectedSchema::class])
            )
        );
    }

    /**
     * A small `__schema` whose root types are not named Query and Mutation
     *
     * @return array{queryType: array{name: string}, mutationType: array{name: string}|null, types: list<mixed>}
     */
    public static function schemaNode(): array
    {
        $string = ['kind' => 'SCALAR', 'name' => 'String', 'ofType' => null];

        return [
            'queryType' => ['name' => 'RootQuery'],
            'mutationType' => ['name' => 'RootMutation'],
            'types' => [
                [
                    'kind' => 'OBJECT',
                    'name' => 'RootQuery',
                    'description' => null,
                    'fields' => [
                        [
                            'name' => 'country',
                            'description' => 'The country with this id. Or none.',
                            'args' => [
                                ['name' => 'id', 'description' => null, 'type' => $string, 'defaultValue' => null],
                            ],
                            'type' => ['kind' => 'OBJECT', 'name' => 'Country', 'ofType' => null],
                            'isDeprecated' => false,
                            'deprecationReason' => null,
                        ],
                    ],
                    'inputFields' => null,
                    'interfaces' => [],
                    'enumValues' => null,
                    'possibleTypes' => null,
                ],
                [
                    'kind' => 'OBJECT',
                    'name' => 'RootMutation',
                    'fields' => [
                        [
                            'name' => 'createEmptyCart',
                            'args' => [],
                            'type' => $string,
                            'isDeprecated' => false,
                            'deprecationReason' => null,
                        ],
                    ],
                ],
                [
                    'kind' => 'OBJECT',
                    'name' => 'Country',
                    'fields' => [['name' => 'id', 'args' => [], 'type' => $string]],
                ],
                ['kind' => 'SCALAR', 'name' => 'String', 'description' => 'Text.'],
            ],
        ];
    }
}
