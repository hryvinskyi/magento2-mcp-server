<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

/**
 * Reads the `__schema` object of an introspection answer into an IntrospectedSchema
 *
 * The object is decoded JSON from the network or the cache, so nothing in it is trusted:
 * - the query type must be named, and the mutation type, when the schema has one;
 * - a type without a string `name` and `kind` is left out; of two types with the same name the first is kept;
 * - a schema whose root types are not among its types is no schema.
 *
 * The schema is created empty by the generated factory and filled through withSchema(), so the decoded arrays never
 * reach the object manager's argument handling.
 */
class SchemaReader
{
    /**
     * @param IntrospectedSchemaFactory $schemaFactory
     */
    public function __construct(
        private readonly IntrospectedSchemaFactory $schemaFactory
    ) {
    }

    /**
     * The schema this `__schema` object describes, or null when it describes none
     *
     * @param array<mixed> $schemaNode
     * @return IntrospectedSchema|null
     */
    public function read(array $schemaNode): ?IntrospectedSchema
    {
        $queryTypeName = $this->rootTypeName($schemaNode['queryType'] ?? null);
        $mutationTypeName = $this->rootTypeName($schemaNode['mutationType'] ?? null);
        $types = $schemaNode['types'] ?? null;
        if ($queryTypeName === '' || !is_array($types)) {
            return null;
        }

        try {
            return $this->schemaFactory->create()->withSchema($queryTypeName, $mutationTypeName, $this->byName($types));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The name of a root type reference (`{name: …}`), or '' when there is none
     *
     * @param mixed $reference
     * @return string
     */
    private function rootTypeName(mixed $reference): string
    {
        $name = is_array($reference) ? ($reference['name'] ?? null) : null;

        return is_string($name) ? $name : '';
    }

    /**
     * The named types, keyed by name, their keys as strings
     *
     * @param array<mixed> $types
     * @return array<string,array<string,mixed>>
     */
    private function byName(array $types): array
    {
        $byName = [];
        foreach ($types as $type) {
            if (!is_array($type)) {
                continue;
            }
            $name = $type['name'] ?? null;
            $kind = $type['kind'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($kind) || isset($byName[$name])) {
                continue;
            }

            $fields = [];
            foreach ($type as $key => $value) {
                $fields[(string) $key] = $value;
            }
            $byName[$name] = $fields;
        }

        return $byName;
    }
}
