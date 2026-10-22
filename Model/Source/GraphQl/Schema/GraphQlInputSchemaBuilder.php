<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

use Psr\Log\LoggerInterface;

/**
 * The JSON Schema of a GraphQL root field's arguments, from the introspected schema
 *
 * - The schema is an object with one property per argument of the field, its description as the description, and
 *   its default value, as GraphQL writes it, named in the description.
 * - An argument is required when its type is non-null and it has no default value: GraphQL lets a call leave out a
 *   non-null argument that has a default.
 * - A field whose result is an object, an interface, a union, or a list of them takes a required `selection`: the
 *   selection set of the result. A field whose result is a scalar or an enum value takes none.
 * - The built-in scalars map to JSON types: `String` and `ID` to `string`, `Int` to `integer`, `Float` to `number`,
 *   `Boolean` to `boolean`. Any other scalar takes any value.
 * - An enum is a string limited to its values; a list is an array of its item type.
 * - An input object is an object with its input fields, the non-null ones without a default required, as GraphQL
 *   requires them.
 * - Input objects nest at most six levels deep. One nested deeper, or one that contains itself, is
 *   `{"type": "object"}` with a description saying its details are omitted, so every schema is finite.
 * - A type the schema does not describe gives a property that takes any value and says so, logged as a warning; it
 *   never stops the rest of the schema from being built.
 * - A JSON object that may be empty (`properties`, the schema `{}`) is an empty \stdClass, never an empty array.
 *
 * @phpstan-import-type Field from NodeReader
 * @phpstan-import-type InputValue from NodeReader
 */
class GraphQlInputSchemaBuilder
{
    /**
     * Name of the argument that carries the selection set of the result
     */
    public const SELECTION = 'selection';

    /**
     * How many input objects may nest inside one another before the next one's details are omitted
     */
    public const MAX_DEPTH = 6;

    /**
     * JSON type of each built-in GraphQL scalar
     */
    private const SCALARS = [
        'String' => 'string',
        'ID' => 'string',
        'Int' => 'integer',
        'Float' => 'number',
        'Boolean' => 'boolean',
    ];

    /**
     * @param NodeReader $nodes
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly NodeReader $nodes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The schema of a root field's arguments
     *
     * @param IntrospectedSchema $schema
     * @param Field $field
     * @return array<string, mixed>
     */
    public function build(IntrospectedSchema $schema, array $field): array
    {
        [$properties, $required] = $this->propertiesOf($schema, $field['args'], 0, []);

        if (!$this->nodes->isLeaf($field['type'])) {
            $properties[self::SELECTION] = [
                'type' => 'string',
                'description' => sprintf(
                    'The GraphQL selection set of the result, of the type %s: exactly one group in braces, such as '
                    . '`{ items { sku name } total_count }`, with nothing after it and no `#` comments. '
                    . 'graphql.schema.describe_type lists the fields of a type.',
                    $this->nodes->typeString($field['type'])
                ),
            ];
            $required[] = self::SELECTION;
        }

        return $this->objectSchema($properties, $required, '');
    }

    /**
     * Properties and required names of a set of arguments or input fields
     *
     * @param IntrospectedSchema $schema
     * @param list<InputValue> $inputValues
     * @param int $depth How many input objects enclose them
     * @param array<string,true> $enclosing Input objects that enclose them
     * @return array{0: array<string, array<mixed>|\stdClass>, 1: list<string>}
     */
    private function propertiesOf(IntrospectedSchema $schema, array $inputValues, int $depth, array $enclosing): array
    {
        $properties = [];
        $required = [];
        foreach ($inputValues as $inputValue) {
            $properties[$inputValue['name']] = $this->describe(
                $this->schemaOf($schema, $inputValue['type'], $depth, $enclosing),
                $inputValue
            );
            if ($this->isRequired($inputValue)) {
                $required[] = $inputValue['name'];
            }
        }

        return [$properties, $required];
    }

    /**
     * The schema of a value of the referenced type
     *
     * @param IntrospectedSchema $schema
     * @param array<mixed> $reference
     * @param int $depth
     * @param array<string,true> $enclosing
     * @return array<string, mixed>|\stdClass
     */
    private function schemaOf(
        IntrospectedSchema $schema,
        array $reference,
        int $depth,
        array $enclosing
    ): array|\stdClass {
        $kind = $this->nodes->kindOf($reference);
        $wrapped = $this->nodes->ofType($reference);
        if ($kind === NodeReader::KIND_NON_NULL && $wrapped !== null) {
            return $this->schemaOf($schema, $wrapped, $depth, $enclosing);
        }
        if ($kind === NodeReader::KIND_LIST && $wrapped !== null) {
            return ['type' => 'array', 'items' => $this->schemaOf($schema, $wrapped, $depth, $enclosing)];
        }

        $name = $this->nodes->nameOf($reference);
        $type = $schema->getType($name);
        if ($type === null) {
            return $this->undescribed($name);
        }

        $kind = $this->nodes->kindOf($type);
        if ($kind === NodeReader::KIND_SCALAR) {
            return $this->scalarSchema($name);
        }
        if ($kind === NodeReader::KIND_ENUM) {
            return $this->enumSchema($type);
        }
        if ($kind === NodeReader::KIND_INPUT_OBJECT) {
            return $this->inputObjectSchema($schema, $name, $type, $depth + 1, $enclosing);
        }

        return $this->undescribed($name);
    }

    /**
     * The schema of a built-in scalar, or one taking any value for another scalar
     *
     * @param string $name
     * @return array<string, mixed>
     */
    private function scalarSchema(string $name): array
    {
        $jsonType = self::SCALARS[$name] ?? null;
        if ($jsonType === null) {
            return ['description' => sprintf('A value of the GraphQL scalar %s.', $name)];
        }

        return ['type' => $jsonType];
    }

    /**
     * A string limited to the enum's values
     *
     * @param array<mixed> $type
     * @return array<string, mixed>
     */
    private function enumSchema(array $type): array
    {
        $values = array_column($this->nodes->enumValuesOf($type), 'name');

        return $values === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $values];
    }

    /**
     * The schema of an input object: its input fields, or a placeholder when nested too deeply or self-containing
     *
     * @param IntrospectedSchema $schema
     * @param string $name
     * @param array<mixed> $type
     * @param int $depth How many input objects enclose the value, this one included
     * @param array<string,true> $enclosing
     * @return array<string, mixed>
     */
    private function inputObjectSchema(
        IntrospectedSchema $schema,
        string $name,
        array $type,
        int $depth,
        array $enclosing
    ): array {
        if (isset($enclosing[$name])) {
            return $this->omitted(sprintf('Details omitted: the type %s contains itself.', $name));
        }
        if ($depth > self::MAX_DEPTH) {
            return $this->omitted(
                sprintf('Details omitted: the type %s is nested more than %d levels deep.', $name, self::MAX_DEPTH)
            );
        }

        $enclosing[$name] = true;
        [$properties, $required] = $this->propertiesOf($schema, $this->nodes->inputFieldsOf($type), $depth, $enclosing);

        return $this->objectSchema($properties, $required, trim($this->nodes->descriptionOf($type)));
    }

    /**
     * The schema of a value of a type the schema does not describe; logged
     *
     * @param string $name
     * @return array<string, mixed>
     */
    private function undescribed(string $name): array
    {
        $this->logger->warning(sprintf(
            'MCP describes a GraphQL argument of the type "%s" as any value: the GraphQL schema does not describe '
            . 'that type as an input type.',
            $name
        ));

        return ['description' => sprintf('A value of the GraphQL type %s, which the schema does not describe.', $name)];
    }

    /**
     * Whether an argument or input field must be sent: its type is non-null and it has no default value
     *
     * @param InputValue $inputValue
     * @return bool
     */
    private function isRequired(array $inputValue): bool
    {
        return $this->nodes->kindOf($inputValue['type']) === NodeReader::KIND_NON_NULL
            && $inputValue['defaultValue'] === null;
    }

    /**
     * The schema described by the argument or input field that holds it, with its default value named
     *
     * That description is more specific than a type's own, so it replaces it; a schema without properties keeps its
     * own description after it, since that says why its details are missing.
     *
     * @param array<string,mixed>|\stdClass $schema
     * @param InputValue $inputValue
     * @return array<string, mixed>|\stdClass
     */
    private function describe(array|\stdClass $schema, array $inputValue): array|\stdClass
    {
        $documentation = trim($inputValue['description']);
        if ($inputValue['defaultValue'] !== null) {
            $documentation = trim(sprintf('%s Defaults to `%s`.', $documentation, $inputValue['defaultValue']));
        }
        if ($documentation === '') {
            return $schema;
        }

        $described = [];
        foreach ((array) $schema as $key => $value) {
            $described[(string) $key] = $value;
        }
        $own = $described['description'] ?? null;
        $described['description'] = is_string($own) && $own !== '' && !isset($described['properties'])
            ? $documentation . ' ' . $own
            : $documentation;

        return $described;
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
     * The schema of an input object whose details are left out
     *
     * @param string $reason
     * @return array<string, mixed>
     */
    private function omitted(string $reason): array
    {
        return ['type' => 'object', 'description' => $reason];
    }
}
