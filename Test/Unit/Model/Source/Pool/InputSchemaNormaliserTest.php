<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Pool;

use Hryvinskyi\McpServer\Model\Source\Pool\InputSchemaNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which empty arrays of a schema written in PHP are JSON objects, judged by the JSON the schema encodes to
 */
class InputSchemaNormaliserTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function schemas(): array
    {
        return [
            'an empty schema is an object schema without properties' => [
                [],
                '{"type":"object","properties":{}}',
            ],
            'empty properties are an object' => [
                ['type' => 'object', 'properties' => []],
                '{"type":"object","properties":{}}',
            ],
            'every map of schemas is an object when empty' => [
                ['patternProperties' => [], '$defs' => [], 'definitions' => [], 'dependentSchemas' => []],
                '{"patternProperties":{},"$defs":{},"definitions":{},"dependentSchemas":{}}',
            ],
            'an empty sub-schema is an object' => [
                ['type' => 'array', 'items' => [], 'additionalProperties' => [], 'not' => []],
                '{"type":"array","items":{},"additionalProperties":{},"not":{}}',
            ],
            'properties named like the positions of a list are still an object' => [
                ['properties' => ['0' => ['type' => 'string'], '1' => ['type' => 'integer', 'properties' => []]]],
                '{"properties":{"0":{"type":"string"},"1":{"type":"integer","properties":{}}}}',
            ],
            'a map with other numbered names keeps its names' => [
                ['$defs' => ['2' => ['type' => 'string'], '7' => []]],
                '{"$defs":{"2":{"type":"string"},"7":{}}}',
            ],
            'the schemas of a property are read the same way, at any depth' => [
                ['properties' => ['address' => ['properties' => ['lines' => ['items' => []]]]]],
                '{"properties":{"address":{"properties":{"lines":{"items":{}}}}}}',
            ],
            'each schema of a list of schemas is read the same way' => [
                ['anyOf' => [[], ['properties' => []]], 'prefixItems' => [['type' => 'string'], []]],
                '{"anyOf":[{},{"properties":{}}],"prefixItems":[{"type":"string"},{}]}',
            ],
            'lists and values are left as they are' => [
                ['required' => [], 'enum' => [], 'default' => [], 'examples' => [[]]],
                '{"required":[],"enum":[],"default":[],"examples":[[]]}',
            ],
            'a boolean schema is left as it is' => [
                ['additionalProperties' => false, 'items' => true],
                '{"additionalProperties":false,"items":true}',
            ],
            'a property named like a keyword is still a schema' => [
                ['properties' => ['required' => ['type' => 'object', 'properties' => []]]],
                '{"properties":{"required":{"type":"object","properties":{}}}}',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param string $json
     * @return void
     */
    #[DataProvider('schemas')]
    public function testTheSchemaEncodesWithItsEmptyObjectsAsObjects(array $schema, string $json): void
    {
        self::assertSame(
            $json,
            json_encode((new InputSchemaNormaliser())->normalise($schema), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }
}
