<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi\Schema;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\Metadata\MetadataReaderInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;

/**
 * The JSON Schema of a Web API ability's arguments, from the metadata Magento's Web API keeps for its service method
 *
 * - The schema is an object with one property per method parameter, its documentation as the description and its
 *   default when it has one. Only method parameters can be required: those the signature does not make optional. A
 *   parameter the route forces is left out: whatever the caller sends for it is replaced.
 * - An update route (PUT) whose URL names the record by a path parameter the method does not take gets that
 *   parameter as an optional property, saying it selects the record to update.
 * - Scalars map to JSON types: `string`, `integer` (int), `number` (float, double) and `boolean`.
 * - `T[]` is an array of `T`.
 * - A data type is an object with its fields (snake case, as REST takes them). No field is ever required: a
 *   getter's type tells what a stored record holds, not what a call must send, and an update sends only what
 *   changes.
 * - `mixed` and `array` take anything: the schema `{}`.
 * - Data types nest at most six levels deep. A data type deeper than that, or one that contains itself, is
 *   `{"type": "object"}` with a description saying its details are omitted, so every schema is finite.
 * - A JSON object that may be empty (`properties`, the schema `{}`) is an empty \stdClass, never an empty array.
 *
 * A schema built while the method or one of its data types is not described is marked as a fallback: it says so in a
 * description, and the cache in front of this builder does not keep it.
 */
class InputSchemaBuilder implements InputSchemaBuilderInterface
{
    /**
     * How many data types may nest inside one another before the next one's details are omitted
     */
    public const MAX_DEPTH = 6;

    private const ARRAY_SUFFIX = '[]';

    /**
     * JSON type of each Web API scalar type
     */
    private const SCALARS = [
        'string' => 'string',
        'int' => 'integer',
        'integer' => 'integer',
        'float' => 'number',
        'double' => 'number',
        'boolean' => 'boolean',
        'bool' => 'boolean',
    ];

    /**
     * Web API types that take any value
     */
    private const ANY = ['anyType', 'mixed', 'array', 'UnstructuredArray'];

    /**
     * @param MetadataReaderInterface $metadata
     * @param InputSchemaFactory $schemaFactory
     */
    public function __construct(
        private readonly MetadataReaderInterface $metadata,
        private readonly InputSchemaFactory $schemaFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(AbilityInterface $ability, Route $route): InputSchema
    {
        $method = $this->metadata->methodOf(
            $route->getServiceClass(),
            $route->getServiceMethod(),
            $route->getVersion()
        );
        if ($method === null) {
            return $this->schemaFactory->create(['fallback' => true])->withSchema([
                'type' => 'object',
                'description' => sprintf(
                    'The parameters of %s::%s() are not described by the Web API.',
                    $route->getServiceClass(),
                    $route->getServiceMethod()
                ),
            ]);
        }

        $fallback = false;
        $forced = $route->getForcedParameterNames();
        $properties = [];
        $required = [];
        foreach ($method['parameters'] as $name => $parameter) {
            if (in_array($name, $forced, true)) {
                continue;
            }

            $schema = $this->describe(
                $this->schemaOf($parameter['type'], 0, [], $fallback),
                $parameter['documentation']
            );
            if (array_key_exists('default', $parameter) && $parameter['default'] !== null) {
                $schema = (array) $schema + ['default' => $parameter['default']];
            }
            $properties[$name] = $schema;

            if ($parameter['required']) {
                $required[] = $name;
            }
        }

        foreach ($route->pathIdNames(array_keys($method['parameters'])) as $name) {
            $properties[$name] = [
                'type' => ['integer', 'string'],
                'description' => sprintf(
                    'Selects the record to update, as the `:%s` of the route %s does: its value is copied into the '
                    . 'record the call saves.',
                    $name,
                    $route->getReference()
                ),
            ];
        }

        return $this->schemaFactory->create(['fallback' => $fallback])
            ->withSchema($this->objectSchema($properties, $required, ''));
    }

    /**
     * The schema of a value of a Web API type
     *
     * @param string $type
     * @param int $depth How many data types enclose the value
     * @param array<string,true> $enclosing Data types that enclose the value
     * @param bool $fallback Set to true when a data type is not described
     * @return array<string, mixed>|\stdClass
     */
    private function schemaOf(string $type, int $depth, array $enclosing, bool &$fallback): array|\stdClass
    {
        if (str_ends_with($type, self::ARRAY_SUFFIX)) {
            return [
                'type' => 'array',
                'items' => $this->schemaOf(
                    substr($type, 0, -strlen(self::ARRAY_SUFFIX)),
                    $depth,
                    $enclosing,
                    $fallback
                ),
            ];
        }

        $scalar = self::SCALARS[$type] ?? null;
        if ($scalar !== null) {
            return ['type' => $scalar];
        }

        if (in_array($type, self::ANY, true)) {
            return (object) [];
        }

        return $this->dataTypeSchema($type, $depth + 1, $enclosing, $fallback);
    }

    /**
     * The schema of a data type: its fields, or a placeholder when it is nested too deeply or contains itself
     *
     * @param string $type
     * @param int $depth How many data types enclose the value, this one included
     * @param array<string,true> $enclosing
     * @param bool $fallback Set to true when this type or one it holds is not described
     * @return array<string, mixed>
     */
    private function dataTypeSchema(string $type, int $depth, array $enclosing, bool &$fallback): array
    {
        if (isset($enclosing[$type])) {
            return $this->omitted(sprintf('Details omitted: the type %s contains itself.', $type));
        }
        if ($depth > self::MAX_DEPTH) {
            return $this->omitted(
                sprintf('Details omitted: the type %s is nested more than %d levels deep.', $type, self::MAX_DEPTH)
            );
        }

        $data = $this->metadata->typeOf($type);
        if ($data === null) {
            $fallback = true;

            return ['description' => sprintf('A value of the type %s, which the Web API does not describe.', $type)];
        }

        $enclosing[$type] = true;
        $properties = [];
        foreach ($data['parameters'] as $field => $definition) {
            $properties[$field] = $this->describe(
                $this->schemaOf($definition['type'], $depth, $enclosing, $fallback),
                $definition['documentation']
            );
        }

        return $this->objectSchema($properties, [], $data['documentation']);
    }

    /**
     * An object schema; empty properties are an empty \stdClass, and `required` is left out when empty
     *
     * @param array<string,array<mixed>|\stdClass> $properties
     * @param list<string> $required
     * @param string $description
     * @return array<string, mixed>
     */
    private function objectSchema(array $properties, array $required, string $description): array
    {
        $schema = ['type' => 'object'];
        if ($description !== '') {
            $schema['description'] = $description;
        }
        $schema['properties'] = $properties === [] ? (object) [] : $properties;
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * The schema of a data type whose details are left out
     *
     * @param string $reason
     * @return array<string, mixed>
     */
    private function omitted(string $reason): array
    {
        return ['type' => 'object', 'description' => $reason];
    }

    /**
     * The schema described by the documentation of the parameter or field that holds it
     *
     * That documentation is more specific than a data type's own, so it replaces it; a schema without properties
     * keeps its own description after it, since that says why its details are missing.
     *
     * @param array<string,mixed>|\stdClass $schema
     * @param string $documentation
     * @return array<string, mixed>|\stdClass
     */
    private function describe(array|\stdClass $schema, string $documentation): array|\stdClass
    {
        if ($documentation === '') {
            return $schema;
        }

        $described = [];
        foreach ((array) $schema as $key => $value) {
            $described[(string) $key] = $value;
        }
        $own = $described['description'] ?? null;
        $described['description'] = is_string($own) && !isset($described['properties'])
            ? $documentation . ' ' . $own
            : $documentation;

        return $described;
    }
}
