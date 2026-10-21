<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi\Schema;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchema;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaFactory;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeMetadataReader;
use PHPUnit\Framework\TestCase;

/**
 * Input schemas from Web API metadata: parameters, data types, scalars, lists, `mixed`, the cycle guard, the depth
 * limit, empty objects that must encode as `{}`, required method parameters only, the record id of an update route,
 * and the fallback mark
 */
class InputSchemaBuilderTest extends TestCase
{
    private const SERVICE = 'Acme\Shop\Api\ThingRepositoryInterface';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;

    public function testGetListTakesSearchCriteriaWithItsNestedTypesAndRequiresNothingInsideThem(): void
    {
        $schema = $this->build('getList', [
            'searchCriteria' => $this->parameter('FrameworkSearchCriteriaInterface', true, 'The search criteria.'),
        ], [
            'FrameworkSearchCriteriaInterface' => $this->type('Search criteria interface.', [
                'filter_groups' => $this->field('FrameworkSearchFilterGroup[]', 'A list of filter groups.'),
                'sort_orders' => $this->field('FrameworkSortOrder[]', 'Sort order.'),
                'page_size' => $this->field('int', 'Page size.'),
                'current_page' => $this->field('int', 'Current page.'),
            ]),
            'FrameworkSearchFilterGroup' => $this->type('Groups filters.', [
                'filters' => $this->field('FrameworkFilter[]', 'Filters.'),
            ]),
            'FrameworkFilter' => $this->type('A filter.', [
                'field' => $this->field('string', 'Field'),
                'value' => $this->field('string', 'Value'),
                'condition_type' => $this->field('string', 'Condition type'),
            ]),
            'FrameworkSortOrder' => $this->type('Sort order.', [
                'field' => $this->field('string', 'Sorting field.'),
                'direction' => $this->field('string', 'Sorting direction.'),
            ]),
        ]);

        self::assertSame(
            '{"type":"object","properties":{"searchCriteria":{"type":"object","description":"The search '
            . 'criteria.","properties":{"filter_groups":{"type":"array","items":{"type":"object","description":'
            . '"Groups filters.","properties":{"filters":{"type":"array","items":{"type":"object","description":'
            . '"A filter.","properties":{"field":{"type":"string","description":"Field"},"value":{"type":"string",'
            . '"description":"Value"},"condition_type":{"type":"string","description":"Condition type"}}'
            . '},"description":"Filters."}}},"description":"A list of filter groups."},'
            . '"sort_orders":{"type":"array","items":{"type":"object","description":"Sort order.","properties":'
            . '{"field":{"type":"string","description":"Sorting field."},"direction":{"type":"string","description":'
            . '"Sorting direction."}}},"description":"Sort order."},"page_size":'
            . '{"type":"integer","description":"Page size."},"current_page":{"type":"integer","description":'
            . '"Current page."}}}},"required":["searchCriteria"]}',
            json_encode($schema, self::JSON_FLAGS)
        );
    }

    public function testRequiredAndOptionalParametersAndTheirDefaults(): void
    {
        $schema = $this->build('save', [
            'sku' => $this->parameter('string', true, 'SKU'),
            'saveOptions' => $this->parameter('boolean', false, 'Save options', false),
            'storeId' => $this->parameter('int', false, '', null),
            'price' => $this->parameter('float', false, ''),
            'weight' => $this->parameter('double', false, ''),
        ]);

        self::assertSame(['sku'], $schema['required']);
        self::assertSame(
            '{"sku":{"type":"string","description":"SKU"},"saveOptions":{"type":"boolean","description":'
            . '"Save options","default":false},"storeId":{"type":"integer"},"price":{"type":"number"},'
            . '"weight":{"type":"number"}}',
            json_encode($schema['properties'], self::JSON_FLAGS)
        );
    }

    public function testAMethodWithoutParametersHasAnEmptyObjectOfProperties(): void
    {
        $schema = $this->build('getCountriesInfo', []);

        self::assertInstanceOf(\stdClass::class, $schema['properties']);
        self::assertArrayNotHasKey('required', $schema);
        self::assertSame('{"type":"object","properties":{}}', json_encode($schema, self::JSON_FLAGS));
    }

    public function testMixedAndArrayTakeAnythingAndEncodeAsAnEmptyObject(): void
    {
        $schema = $this->build('set', [
            'value' => $this->parameter('anyType', true, ''),
            'values' => $this->parameter('mixed[]', true, ''),
            'raw' => $this->parameter('UnstructuredArray', false, 'Anything at all'),
        ]);

        self::assertSame(
            '{"value":{},"values":{"type":"array","items":{}},"raw":{"description":"Anything at all"}}',
            json_encode($schema['properties'], self::JSON_FLAGS)
        );
    }

    public function testAFieldsDocumentationReplacesItsTypesAndKeepsTheNoteOfAnOmittedType(): void
    {
        $schema = $this->build('save', [
            'node' => $this->parameter('AcmeShopDataNodeInterface', true, 'The node to save.'),
        ], [
            'AcmeShopDataNodeInterface' => $this->type('A tree node.', [
                'parent' => $this->field('AcmeShopDataNodeInterface', 'The parent node.'),
            ]),
        ]);

        $node = $this->arrayAt($schema, 'properties', 'node');
        self::assertSame('The node to save.', $node['description']);
        self::assertSame(
            'The parent node. Details omitted: the type AcmeShopDataNodeInterface contains itself.',
            $this->arrayAt($node, 'properties', 'parent')['description']
        );
    }

    public function testATypeThatContainsItselfStopsAtTheCycle(): void
    {
        $schema = $this->build('save', ['node' => $this->parameter('AcmeShopDataNodeInterface', true, '')], [
            'AcmeShopDataNodeInterface' => $this->type('A tree node.', [
                'name' => $this->field('string', ''),
                'children' => $this->field('AcmeShopDataNodeInterface[]', 'Child nodes.'),
            ]),
        ]);

        $node = $this->arrayAt($schema, 'properties', 'node');
        self::assertArrayNotHasKey('required', $node);
        self::assertSame(
            [
                'type' => 'object',
                'description' => 'Details omitted: the type AcmeShopDataNodeInterface contains itself.',
            ],
            $this->arrayAt($node, 'properties', 'children', 'items')
        );
    }

    public function testDataTypesNestSixLevelsAndTheSeventhIsOmitted(): void
    {
        $types = [];
        for ($level = 1; $level <= 8; $level++) {
            $types['AcmeShopDataLevel' . $level] = $this->type('Level ' . $level . '.', [
                'next' => $this->field('AcmeShopDataLevel' . ($level + 1), ''),
            ]);
        }
        $schema = $this->build('save', ['level' => $this->parameter('AcmeShopDataLevel1', true, '')], $types);

        $node = $this->arrayAt($schema, 'properties', 'level');
        for ($level = 1; $level <= 6; $level++) {
            self::assertSame('Level ' . $level . '.', $node['description']);
            $node = $this->arrayAt($node, 'properties', 'next');
        }
        self::assertSame(
            [
                'type' => 'object',
                'description' => 'Details omitted: the type AcmeShopDataLevel7 is nested more than 6 levels deep.',
            ],
            $node
        );
    }

    public function testAParameterTheRouteForcesIsLeftOut(): void
    {
        $route = (new Route('/V1/things/mine', 'GET', self::SERVICE, 'getForCustomer'))->withParameters([
            'customerId' => ['force' => true, 'value' => '%customer_id%'],
            'storeId' => ['force' => false, 'value' => '1'],
        ]);
        $reader = new FakeMetadataReader([self::SERVICE . '::getForCustomer' => [
            'documentation' => '',
            'parameters' => [
                'customerId' => $this->parameter('int', true, ''),
                'storeId' => $this->parameter('int', false, ''),
            ],
        ]]);

        $schema = $this->builder($reader)->build($this->ability(), $route)->getSchema();

        self::assertSame(['storeId'], array_keys($this->arrayAt($schema, 'properties')));
        self::assertArrayNotHasKey('required', $schema);
    }

    public function testAnUndescribedMethodOrTypeSaysSoAndIsMarkedAsAFallback(): void
    {
        $route = new Route('/V1/things', 'GET', self::SERVICE, 'unknown');
        $unknownMethod = $this->builder(new FakeMetadataReader())->build($this->ability(), $route);
        $unknownType = $this->built('save', ['thing' => $this->parameter('AcmeShopDataGhostInterface', true, '')]);
        $thing = ['thing' => $this->parameter('AcmeShopDataThingInterface', true, '')];
        $nestedUnknownType = $this->built('save', $thing, [
            'AcmeShopDataThingInterface' => $this->type('A thing.', [
                'ghosts' => $this->field('AcmeShopDataGhostInterface[]', ''),
            ]),
        ]);

        self::assertTrue($unknownMethod->isFallback());
        self::assertSame('object', $unknownMethod->getSchema()['type']);
        self::assertStringContainsString(
            'not described',
            $this->text($unknownMethod->getSchema()['description'] ?? null)
        );
        self::assertTrue($unknownType->isFallback());
        self::assertSame(
            ['description' => 'A value of the type AcmeShopDataGhostInterface, which the Web API does not describe.'],
            $this->arrayAt($unknownType->getSchema(), 'properties', 'thing')
        );
        self::assertTrue($nestedUnknownType->isFallback());
    }

    public function testADescribedSchemaIsNoFallbackEvenWhereItsDetailsAreOmitted(): void
    {
        $schema = $this->built('save', ['node' => $this->parameter('AcmeShopDataNodeInterface', true, '')], [
            'AcmeShopDataNodeInterface' => $this->type('A tree node.', [
                'children' => $this->field('AcmeShopDataNodeInterface[]', ''),
            ]),
        ]);

        self::assertFalse($schema->isFallback());
    }

    public function testASaveRequiresTheRecordAtTheTopAndNothingInsideIt(): void
    {
        $schema = $this->build('save', [
            'product' => $this->parameter('CatalogDataProductInterface', true, ''),
            'saveOptions' => $this->parameter('boolean', false, '', false),
        ], [
            'CatalogDataProductInterface' => $this->type('Product.', [
                'sku' => $this->field('string', 'Sku'),
                'name' => $this->field('string', 'Name'),
                'extension_attributes' => $this->field('CatalogDataProductExtensionInterface', ''),
            ]),
            'CatalogDataProductExtensionInterface' => $this->type('Extension.', [
                'website_ids' => $this->field('int[]', ''),
            ]),
        ]);

        self::assertSame(['product'], $schema['required']);
        $product = $this->arrayAt($schema, 'properties', 'product');
        self::assertArrayNotHasKey('required', $product);
        self::assertArrayNotHasKey('required', $this->arrayAt($product, 'properties', 'extension_attributes'));
    }

    public function testAnUpdateRouteTakesTheRecordIdOfItsPathAsAnOptionalProperty(): void
    {
        $reader = new FakeMetadataReader([self::SERVICE . '::save' => [
            'documentation' => '',
            'parameters' => ['entity' => $this->parameter('AcmeShopDataThingInterface', true, '')],
        ]], ['AcmeShopDataThingInterface' => $this->type('A thing.', ['name' => $this->field('string', '')])]);
        $route = new Route('/V1/things/:parent_id', 'PUT', self::SERVICE, 'save');

        $schema = $this->builder($reader)->build($this->ability(), $route)->getSchema();

        self::assertSame(['entity', 'parent_id'], array_keys($this->arrayAt($schema, 'properties')));
        self::assertSame(['entity'], $schema['required']);
        $pathId = $this->arrayAt($schema, 'properties', 'parent_id');
        self::assertSame(['integer', 'string'], $pathId['type']);
        self::assertStringContainsString('Selects the record to update', $this->text($pathId['description'] ?? null));
    }

    public function testACreateRouteOrAPathParameterTheMethodTakesAddsNoRecordId(): void
    {
        $reader = new FakeMetadataReader([
            self::SERVICE . '::save' => [
                'documentation' => '',
                'parameters' => ['entity' => $this->parameter('string', true, '')],
            ],
            self::SERVICE . '::rename' => [
                'documentation' => '',
                'parameters' => [
                    'thingId' => $this->parameter('int', true, ''),
                    'name' => $this->parameter('string', true, ''),
                ],
            ],
        ]);

        $create = $this->builder($reader)
            ->build($this->ability(), new Route('/V1/things/:parent_id', 'POST', self::SERVICE, 'save'))
            ->getSchema();
        $rename = $this->builder($reader)
            ->build($this->ability(), new Route('/V1/things/:thing_id', 'PUT', self::SERVICE, 'rename'))
            ->getSchema();

        self::assertSame(['entity'], array_keys($this->arrayAt($create, 'properties')));
        self::assertSame(['thingId', 'name'], array_keys($this->arrayAt($rename, 'properties')));
    }

    /**
     * Build the schema of a method of the test service
     *
     * @param string $method
     * @param array<string, array{type: string, required: bool, documentation: string, default?: mixed}> $parameters
     * @param array<string, array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, documentation: string}>
     * }> $types
     * @return array<string, mixed>
     */
    private function build(string $method, array $parameters, array $types = []): array
    {
        return $this->built($method, $parameters, $types)->getSchema();
    }

    /**
     * The built schema of a method of the test service, with its fallback mark
     *
     * @param string $method
     * @param array<string, array{type: string, required: bool, documentation: string, default?: mixed}> $parameters
     * @param array<string, array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, documentation: string}>
     * }> $types
     * @return InputSchema
     */
    private function built(string $method, array $parameters, array $types = []): InputSchema
    {
        $reader = new FakeMetadataReader(
            [self::SERVICE . '::' . $method => ['documentation' => 'Documented.', 'parameters' => $parameters]],
            $types
        );

        return $this->builder($reader)->build(
            $this->ability(),
            new Route('/V1/things', 'POST', self::SERVICE, $method)
        );
    }

    /**
     * @param FakeMetadataReader $reader
     * @return InputSchemaBuilder
     */
    private function builder(FakeMetadataReader $reader): InputSchemaBuilder
    {
        return new InputSchemaBuilder(
            $reader,
            new InputSchemaFactory(new ConstructingObjectManager([InputSchema::class => InputSchema::class]))
        );
    }

    /**
     * @param string $type
     * @param bool $required
     * @param string $documentation
     * @param mixed ...$default At most one default value
     * @return array{type: string, required: bool, documentation: string, default?: mixed}
     */
    private function parameter(string $type, bool $required, string $documentation, mixed ...$default): array
    {
        $parameter = ['type' => $type, 'required' => $required, 'documentation' => $documentation];
        if ($default !== []) {
            $parameter['default'] = $default[0] ?? null;
        }

        return $parameter;
    }

    /**
     * @param string $type
     * @param string $documentation
     * @return array{type: string, documentation: string}
     */
    private function field(string $type, string $documentation): array
    {
        return ['type' => $type, 'documentation' => $documentation];
    }

    /**
     * @param string $documentation
     * @param array<string, array{type: string, documentation: string}> $fields
     * @return array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, documentation: string}>
     * }
     */
    private function type(string $documentation, array $fields): array
    {
        return ['documentation' => $documentation, 'parameters' => $fields];
    }

    /**
     * The array at a path of keys, failing the test when there is none
     *
     * @param array<mixed> $schema
     * @param string ...$path
     * @return array<mixed>
     */
    private function arrayAt(array $schema, string ...$path): array
    {
        $node = $schema;
        foreach ($path as $key) {
            $next = $node[$key] ?? null;
            self::assertIsArray($next, sprintf('No array at "%s".', $key));
            $node = $next;
        }

        return $node;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return Ability
     */
    private function ability(): Ability
    {
        return new Ability(
            'acme_shop.thing_repository.save',
            'Save a thing',
            'Acme_Shop',
            'webapi',
            'POST /V1/things',
            AbilityAccess::Write
        );
    }
}
