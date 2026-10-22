<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;

/**
 * Builds introspection nodes, as the GraphQL endpoint answers them, for tests
 */
class GraphQlSchemaKit
{
    /**
     * A reference to a named type
     *
     * @param string $kind
     * @param string $name
     * @return array<string, mixed>
     */
    public static function named(string $kind, string $name): array
    {
        return ['kind' => $kind, 'name' => $name, 'ofType' => null];
    }

    /**
     * A reference to a scalar
     *
     * @param string $name
     * @return array<string, mixed>
     */
    public static function scalar(string $name): array
    {
        return self::named('SCALAR', $name);
    }

    /**
     * A non-null reference
     *
     * @param array<string,mixed> $reference
     * @return array<string, mixed>
     */
    public static function nonNull(array $reference): array
    {
        return ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $reference];
    }

    /**
     * A list reference
     *
     * @param array<string,mixed> $reference
     * @return array<string, mixed>
     */
    public static function listOf(array $reference): array
    {
        return ['kind' => 'LIST', 'name' => null, 'ofType' => $reference];
    }

    /**
     * An argument or input field
     *
     * @param string $name
     * @param array<string,mixed> $type
     * @param string|null $defaultValue
     * @param string $description
     * @return array<string, mixed>
     */
    public static function input(
        string $name,
        array $type,
        ?string $defaultValue = null,
        string $description = ''
    ): array {
        return ['name' => $name, 'description' => $description, 'type' => $type, 'defaultValue' => $defaultValue];
    }

    /**
     * A field
     *
     * @param string $name
     * @param array<string,mixed> $type
     * @param list<array<string,mixed>> $args
     * @param string $description
     * @param string|null $deprecationReason Null for a field that is not deprecated
     * @return array<string, mixed>
     */
    public static function field(
        string $name,
        array $type,
        array $args = [],
        string $description = '',
        ?string $deprecationReason = null
    ): array {
        return [
            'name' => $name,
            'description' => $description,
            'args' => $args,
            'type' => $type,
            'isDeprecated' => $deprecationReason !== null,
            'deprecationReason' => $deprecationReason,
        ];
    }

    /**
     * An object type
     *
     * @param string $name
     * @param list<array<string,mixed>> $fields
     * @return array<string, mixed>
     */
    public static function objectType(string $name, array $fields): array
    {
        return [
            'kind' => 'OBJECT',
            'name' => $name,
            'description' => '',
            'fields' => $fields,
            'inputFields' => null,
            'interfaces' => [],
            'enumValues' => null,
            'possibleTypes' => null,
        ];
    }

    /**
     * An input object type
     *
     * @param string $name
     * @param list<array<string,mixed>> $inputFields
     * @param string $description
     * @return array<string, mixed>
     */
    public static function inputType(string $name, array $inputFields, string $description = ''): array
    {
        return [
            'kind' => 'INPUT_OBJECT',
            'name' => $name,
            'description' => $description,
            'fields' => null,
            'inputFields' => $inputFields,
            'interfaces' => null,
            'enumValues' => null,
            'possibleTypes' => null,
        ];
    }

    /**
     * An enum type
     *
     * @param string $name
     * @param list<string> $values
     * @return array<string, mixed>
     */
    public static function enumType(string $name, array $values): array
    {
        return [
            'kind' => 'ENUM',
            'name' => $name,
            'description' => '',
            'fields' => null,
            'inputFields' => null,
            'interfaces' => null,
            'enumValues' => array_map(
                static fn (string $value): array => [
                    'name' => $value,
                    'description' => '',
                    'isDeprecated' => false,
                    'deprecationReason' => null,
                ],
                $values
            ),
            'possibleTypes' => null,
        ];
    }

    /**
     * A scalar type
     *
     * @param string $name
     * @return array<string, mixed>
     */
    public static function scalarType(string $name): array
    {
        return ['kind' => 'SCALAR', 'name' => $name, 'description' => ''];
    }

    /**
     * A schema of these types, with the built-in scalars added
     *
     * @param list<array<string,mixed>> $queryFields
     * @param list<array<string,mixed>> $mutationFields None for a schema without mutations
     * @param list<array<string,mixed>> $types
     * @return IntrospectedSchema
     */
    public static function schema(array $queryFields, array $mutationFields = [], array $types = []): IntrospectedSchema
    {
        $byName = ['Query' => self::objectType('Query', $queryFields)];
        if ($mutationFields !== []) {
            $byName['Mutation'] = self::objectType('Mutation', $mutationFields);
        }
        foreach (['String', 'ID', 'Int', 'Float', 'Boolean'] as $scalar) {
            $byName[$scalar] = self::scalarType($scalar);
        }
        foreach ($types as $type) {
            $name = $type['name'] ?? null;
            if (is_string($name)) {
                $byName[$name] = $type;
            }
        }

        return (new IntrospectedSchema())->withSchema('Query', $mutationFields === [] ? '' : 'Mutation', $byName);
    }
}
