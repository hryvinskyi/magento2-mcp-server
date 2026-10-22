<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Pool;

/**
 * Gives a JSON Schema written as PHP arrays the form the module answers with: an empty JSON object is an empty
 * \stdClass, so that it encodes as `{}` and never as `[]`
 *
 * PHP has one empty array for `{}` and `[]`. In a schema the keyword decides which one was meant:
 * - an empty schema is an object schema without properties;
 * - the keywords that hold a map of named schemas (`properties`, `patternProperties`, `$defs`, `definitions`,
 *   `dependentSchemas`) are objects, and each schema in them is read the same way;
 * - the keywords that hold one schema or a list of schemas (`items`, `additionalProperties`, `anyOf`, `not`, …) hold
 *   `{}` when empty, and each schema in them is read the same way;
 * - every other keyword (`required`, `enum`, `default`, …) is left as it is.
 */
class InputSchemaNormaliser
{
    /**
     * Keywords whose value is a JSON object mapping names to schemas
     */
    private const SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'];

    /**
     * Keywords whose value is a schema, or a list of schemas
     */
    private const SUBSCHEMAS = [
        'items',
        'prefixItems',
        'additionalItems',
        'additionalProperties',
        'unevaluatedItems',
        'unevaluatedProperties',
        'propertyNames',
        'contains',
        'not',
        'if',
        'then',
        'else',
        'allOf',
        'anyOf',
        'oneOf',
    ];

    /**
     * The schema with every JSON object that may be empty given as an \stdClass when it is empty
     *
     * @param array<string,mixed> $schema
     * @return array<string, mixed>
     */
    public function normalise(array $schema): array
    {
        if ($schema === []) {
            return ['type' => 'object', 'properties' => (object) []];
        }

        return $this->members($schema);
    }

    /**
     * A schema's members, each read by the rule of its keyword
     *
     * @param array<mixed> $schema
     * @return array<string, mixed>
     */
    private function members(array $schema): array
    {
        $members = [];
        foreach ($schema as $keyword => $value) {
            $members[(string) $keyword] = $this->member((string) $keyword, $value);
        }

        return $members;
    }

    /**
     * One member of a schema, read by the rule of its keyword
     *
     * @param string $keyword
     * @param mixed $value
     * @return mixed
     */
    private function member(string $keyword, mixed $value): mixed
    {
        if (in_array($keyword, self::SCHEMA_MAPS, true)) {
            return $this->schemaMap($value);
        }
        if (in_array($keyword, self::SUBSCHEMAS, true)) {
            return $this->subschema($value);
        }

        return $value;
    }

    /**
     * A map of named schemas: `{}` when empty, else each schema normalised
     *
     * @param mixed $value
     * @return mixed
     */
    private function schemaMap(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return (object) [];
        }

        $map = [];
        foreach ($value as $name => $schema) {
            $map[(string) $name] = $this->subschema($schema);
        }

        return $map;
    }

    /**
     * A schema, or a list of schemas: `{}` when empty, else each schema normalised
     *
     * A boolean schema (`additionalProperties: false`) is left as it is.
     *
     * @param mixed $value
     * @return mixed
     */
    private function subschema(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === []) {
            return (object) [];
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $schema): mixed => $this->subschema($schema), $value);
        }

        return $this->members($value);
    }
}
