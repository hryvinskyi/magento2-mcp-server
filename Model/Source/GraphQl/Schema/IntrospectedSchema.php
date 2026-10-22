<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

/**
 * A GraphQL schema as introspection describes it: its types by name and the names of its root types
 *
 * - Each type is the introspection's `__Type` object as an array (`kind`, `name`, `description`, `fields`,
 *   `inputFields`, `interfaces`, `enumValues`, `possibleTypes`), type references nested through `ofType`.
 * - The root type names come from the schema itself, never assumed to be `Query` and `Mutation`. A schema without
 *   mutations has '' as its mutation type name.
 *
 * The introspection comes from the network, so it never passes through the object manager's argument handling: the
 * generated factory creates an empty schema and withSchema() returns a filled copy. withSchema() refuses root type
 * names that the types do not hold, so a filled schema always holds its root types.
 */
class IntrospectedSchema
{
    /**
     * @var string
     */
    private string $queryTypeName = '';

    /**
     * @var string
     */
    private string $mutationTypeName = '';

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $types = [];

    /**
     * A copy holding this schema
     *
     * @param string $queryTypeName Name of the type holding the query fields
     * @param string $mutationTypeName Name of the type holding the mutation fields, '' for none
     * @param array<string,array<string,mixed>> $types Types keyed by their name
     * @return static
     * @throws \InvalidArgumentException When a root type is not among the types
     */
    public function withSchema(string $queryTypeName, string $mutationTypeName, array $types): static
    {
        if ($queryTypeName === '' || !isset($types[$queryTypeName])) {
            throw new \InvalidArgumentException(sprintf('The query type "%s" is not in the schema.', $queryTypeName));
        }
        if ($mutationTypeName !== '' && !isset($types[$mutationTypeName])) {
            throw new \InvalidArgumentException(
                sprintf('The mutation type "%s" is not in the schema.', $mutationTypeName)
            );
        }

        $copy = clone $this;
        $copy->queryTypeName = $queryTypeName;
        $copy->mutationTypeName = $mutationTypeName;
        $copy->types = $types;

        return $copy;
    }

    /**
     * Name of the type holding the query fields
     *
     * @return string
     */
    public function getQueryTypeName(): string
    {
        return $this->queryTypeName;
    }

    /**
     * Name of the type holding the mutation fields, or '' when the schema has no mutations
     *
     * @return string
     */
    public function getMutationTypeName(): string
    {
        return $this->mutationTypeName;
    }

    /**
     * The type with this name, or null when the schema has none
     *
     * @param string $name
     * @return array<string,mixed>|null
     */
    public function getType(string $name): ?array
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Every type, keyed by its name
     *
     * @return array<string,array<string,mixed>>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * The schema in the shape of an introspection answer's `__schema`, which SchemaReader reads back
     *
     * @return array{
     *     queryType: array{name: string},
     *     mutationType: array{name: string}|null,
     *     types: list<array<string,mixed>>
     * }
     */
    public function toIntrospection(): array
    {
        return [
            'queryType' => ['name' => $this->queryTypeName],
            'mutationType' => $this->mutationTypeName === '' ? null : ['name' => $this->mutationTypeName],
            'types' => array_values($this->types),
        ];
    }
}
