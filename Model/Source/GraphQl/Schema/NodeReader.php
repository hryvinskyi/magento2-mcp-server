<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

/**
 * Reads the nodes of an introspected schema (a type's fields, arguments, input fields and enum values, and the type
 * references between them) into typed values
 *
 * The nodes come from the network, so nothing in them is trusted: an entry without a string name, or a field or
 * argument without a type reference, is left out; a missing description is ''; a missing default value is null.
 *
 * A type reference is the introspection's `{kind, name, ofType}`: `NON_NULL` and `LIST` wrap the reference in
 * `ofType`, every other kind names a type.
 *
 * @phpstan-type InputValue array{name: string, description: string, type: array<mixed>, defaultValue: string|null}
 * @phpstan-type Field array{
 *     name: string,
 *     description: string,
 *     args: list<InputValue>,
 *     type: array<mixed>,
 *     isDeprecated: bool,
 *     deprecationReason: string
 * }
 * @phpstan-type EnumValue array{name: string, description: string, isDeprecated: bool, deprecationReason: string}
 */
class NodeReader
{
    /**
     * The form of every GraphQL name: of a type, a field, an argument
     */
    public const NAME_PATTERN = '/^[_A-Za-z][_0-9A-Za-z]*$/D';

    public const KIND_NON_NULL = 'NON_NULL';
    public const KIND_LIST = 'LIST';
    public const KIND_SCALAR = 'SCALAR';
    public const KIND_ENUM = 'ENUM';
    public const KIND_INPUT_OBJECT = 'INPUT_OBJECT';

    /**
     * How many `NON_NULL` and `LIST` wrappers a type reference may have; introspection gives at most seven
     */
    private const MAX_WRAPPERS = 16;

    /**
     * The fields of an object or interface type
     *
     * @param array<mixed> $type
     * @return list<Field>
     */
    public function fieldsOf(array $type): array
    {
        $fields = [];
        foreach ($this->entries($type['fields'] ?? null) as $node) {
            $name = $this->text($node['name'] ?? null);
            $reference = $node['type'] ?? null;
            if ($name === '' || !is_array($reference)) {
                continue;
            }

            $fields[] = [
                'name' => $name,
                'description' => $this->text($node['description'] ?? null),
                'args' => $this->inputValues($node['args'] ?? null),
                'type' => $reference,
                'isDeprecated' => ($node['isDeprecated'] ?? false) === true,
                'deprecationReason' => $this->text($node['deprecationReason'] ?? null),
            ];
        }

        return $fields;
    }

    /**
     * The input fields of an input object type
     *
     * @param array<mixed> $type
     * @return list<InputValue>
     */
    public function inputFieldsOf(array $type): array
    {
        return $this->inputValues($type['inputFields'] ?? null);
    }

    /**
     * The values of an enum type
     *
     * @param array<mixed> $type
     * @return list<EnumValue>
     */
    public function enumValuesOf(array $type): array
    {
        $values = [];
        foreach ($this->entries($type['enumValues'] ?? null) as $node) {
            $name = $this->text($node['name'] ?? null);
            if ($name === '') {
                continue;
            }

            $values[] = [
                'name' => $name,
                'description' => $this->text($node['description'] ?? null),
                'isDeprecated' => ($node['isDeprecated'] ?? false) === true,
                'deprecationReason' => $this->text($node['deprecationReason'] ?? null),
            ];
        }

        return $values;
    }

    /**
     * Names of the types a list of type references names (`interfaces`, `possibleTypes`)
     *
     * @param mixed $references
     * @return list<string>
     */
    public function typeNames(mixed $references): array
    {
        $names = [];
        foreach ($this->entries($references) as $reference) {
            $name = $this->nameOf($reference);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The description of a type, '' when it has none
     *
     * @param array<mixed> $type
     * @return string
     */
    public function descriptionOf(array $type): string
    {
        return $this->text($type['description'] ?? null);
    }

    /**
     * The kind of a type reference, '' when it has none
     *
     * @param array<mixed> $reference
     * @return string
     */
    public function kindOf(array $reference): string
    {
        return $this->text($reference['kind'] ?? null);
    }

    /**
     * The type name of a type reference, '' for a wrapper or a reference without one
     *
     * @param array<mixed> $reference
     * @return string
     */
    public function nameOf(array $reference): string
    {
        return $this->text($reference['name'] ?? null);
    }

    /**
     * The reference a `NON_NULL` or `LIST` reference wraps, or null
     *
     * @param array<mixed> $reference
     * @return array<mixed>|null
     */
    public function ofType(array $reference): ?array
    {
        $wrapped = $reference['ofType'] ?? null;

        return is_array($wrapped) ? $wrapped : null;
    }

    /**
     * The reference to the named type inside every `NON_NULL` and `LIST` wrapper
     *
     * @param array<mixed> $reference
     * @return array<mixed>
     */
    public function namedOf(array $reference): array
    {
        for ($wrappers = 0; $wrappers < self::MAX_WRAPPERS; $wrappers++) {
            $wrapped = $this->ofType($reference);
            if ($wrapped === null) {
                return $reference;
            }
            $reference = $wrapped;
        }

        return $reference;
    }

    /**
     * Whether a value of the referenced type is a scalar or an enum value, which takes no selection set
     *
     * @param array<mixed> $reference
     * @return bool
     */
    public function isLeaf(array $reference): bool
    {
        return in_array($this->kindOf($this->namedOf($reference)), [self::KIND_SCALAR, self::KIND_ENUM], true);
    }

    /**
     * The reference written as in a GraphQL document (`[String!]!`), or '' when it is malformed
     *
     * Malformed: a wrapper without the reference it wraps, `NON_NULL` inside `NON_NULL`, a named type whose name is
     * not a GraphQL name, or more wrappers than any schema uses.
     *
     * @param array<mixed> $reference
     * @return string
     */
    public function typeString(array $reference): string
    {
        return $this->written($reference, 0);
    }

    /**
     * Whether the text is a GraphQL name
     *
     * @param string $name
     * @return bool
     */
    public function isName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * A type reference written as in a document, '' when malformed
     *
     * @param array<mixed> $reference
     * @param int $wrappers How many wrappers enclose it
     * @return string
     */
    private function written(array $reference, int $wrappers): string
    {
        if ($wrappers > self::MAX_WRAPPERS) {
            return '';
        }

        $kind = $this->kindOf($reference);
        $wrapped = $this->ofType($reference);
        if ($kind === self::KIND_NON_NULL) {
            $inner = $wrapped === null ? '' : $this->written($wrapped, $wrappers + 1);

            return $inner === '' || str_ends_with($inner, '!') ? '' : $inner . '!';
        }
        if ($kind === self::KIND_LIST) {
            $inner = $wrapped === null ? '' : $this->written($wrapped, $wrappers + 1);

            return $inner === '' ? '' : '[' . $inner . ']';
        }

        $name = $this->nameOf($reference);

        return $this->isName($name) ? $name : '';
    }

    /**
     * Arguments or input fields
     *
     * @param mixed $nodes
     * @return list<InputValue>
     */
    private function inputValues(mixed $nodes): array
    {
        $values = [];
        foreach ($this->entries($nodes) as $node) {
            $name = $this->text($node['name'] ?? null);
            $reference = $node['type'] ?? null;
            if ($name === '' || !is_array($reference)) {
                continue;
            }

            $default = $node['defaultValue'] ?? null;
            $values[] = [
                'name' => $name,
                'description' => $this->text($node['description'] ?? null),
                'type' => $reference,
                'defaultValue' => is_string($default) ? $default : null,
            ];
        }

        return $values;
    }

    /**
     * The array entries of a list node; nothing for anything else
     *
     * @param mixed $nodes
     * @return list<array<mixed>>
     */
    private function entries(mixed $nodes): array
    {
        if (!is_array($nodes)) {
            return [];
        }

        return array_values(array_filter($nodes, 'is_array'));
    }

    /**
     * A string node as text, '' for anything else
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
