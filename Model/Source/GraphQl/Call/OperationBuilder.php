<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Call;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Operation;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\GraphQlInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Magento\Framework\Exception\LocalizedException;

/**
 * Writes the one GraphQL operation that calls a root field with a client's arguments
 *
 * The document is `query($a: Type!, …) {` / the field with its arguments / the selection on its own line / `}`:
 * - The arguments go only into the variables, never into the document text. Only the arguments the client sent
 *   appear in the variable definitions and in the field's argument list, so an argument left out takes the field's
 *   own default; a declared but unsent variable would fail a non-null argument that has one.
 * - The variable types are the introspected argument types as GraphQL writes them (`[String!]!`).
 * - The selection, required when the field's result is not a scalar or an enum value, passes the selection check
 *   first and is written on its own line, with a line break before the operation's closing brace.
 * - An argument the field does not take is refused, naming it; so is a `selection` for a field whose result takes
 *   none.
 * - Client JSON objects arrive as PHP arrays, which encode as `[]` when empty: an empty array sent where the field
 *   takes an input object becomes the empty JSON object again, at any depth of input objects and lists.
 * - A field, argument or type name that is not a GraphQL name is refused as a schema the server cannot describe, so
 *   nothing from the network is written into the document unchecked.
 *
 * @phpstan-import-type Field from NodeReader
 * @phpstan-import-type InputValue from NodeReader
 */
class OperationBuilder
{
    /**
     * How many input objects and lists a variable value is followed into; deeper values are sent as they are
     */
    private const MAX_VALUE_DEPTH = 32;

    /**
     * @param NodeReader $nodes
     * @param SelectionValidator $selectionValidator
     */
    public function __construct(
        private readonly NodeReader $nodes,
        private readonly SelectionValidator $selectionValidator
    ) {
    }

    /**
     * The document and the variables that call the field with these arguments
     *
     * @param IntrospectedSchema $schema
     * @param Operation $operation
     * @param Field $field
     * @param array<string,mixed> $arguments The client's arguments, `selection` included
     * @return array{document: string, variables: array<string, mixed>}
     * @throws LocalizedException When the arguments do not suit the field
     */
    public function build(IntrospectedSchema $schema, Operation $operation, array $field, array $arguments): array
    {
        if (!$this->nodes->isName($field['name'])) {
            $this->refuseSchema($field['name']);
        }

        $isLeaf = $this->nodes->isLeaf($field['type']);
        $selection = $isLeaf ? '' : $this->selectionOf($arguments);
        if (!$isLeaf) {
            unset($arguments[GraphQlInputSchemaBuilder::SELECTION]);
        }
        $this->assertKnown($field, $arguments);

        $definitions = [];
        $fieldArguments = [];
        $variables = [];
        foreach ($field['args'] as $argument) {
            $name = $argument['name'];
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $type = $this->nodes->typeString($argument['type']);
            if ($type === '' || !$this->nodes->isName($name)) {
                $this->refuseSchema($field['name']);
            }
            $definitions[] = sprintf('$%s: %s', $name, $type);
            $fieldArguments[] = sprintf('%1$s: $%1$s', $name);
            $variables[$name] = $this->valueOf($schema, $argument['type'], $arguments[$name], 0);
        }

        $document = $operation->value
            . ($definitions === [] ? '' : '(' . implode(', ', $definitions) . ')')
            . " {\n  " . $field['name']
            . ($fieldArguments === [] ? '' : '(' . implode(', ', $fieldArguments) . ')')
            . ($isLeaf ? '' : "\n" . $selection)
            . "\n}";

        return ['document' => $document, 'variables' => $variables];
    }

    /**
     * The client's selection, checked
     *
     * @param array<string,mixed> $arguments
     * @return string
     * @throws LocalizedException
     */
    private function selectionOf(array $arguments): string
    {
        $selection = $arguments[GraphQlInputSchemaBuilder::SELECTION] ?? null;
        if (!is_string($selection) || trim($selection) === '') {
            throw new LocalizedException(__(
                'Send the selection set of the result in the argument "%1", such as %2.',
                GraphQlInputSchemaBuilder::SELECTION,
                '{ items { sku name } }'
            ));
        }
        $this->selectionValidator->validate($selection);

        return $selection;
    }

    /**
     * Refuse arguments the field does not take
     *
     * @param Field $field
     * @param array<string,mixed> $arguments
     * @return void
     * @throws LocalizedException
     */
    private function assertKnown(array $field, array $arguments): void
    {
        $unknown = array_diff(array_keys($arguments), array_column($field['args'], 'name'));
        if ($unknown === []) {
            return;
        }

        throw new LocalizedException(__(
            'The GraphQL field "%1" takes no argument named %2.',
            $field['name'],
            implode(', ', array_map(static fn (int|string $name): string => '"' . $name . '"', $unknown))
        ));
    }

    /**
     * The value sent for a variable of the referenced type: client JSON objects given back their object form
     *
     * @param IntrospectedSchema $schema
     * @param array<mixed> $reference
     * @param mixed $value
     * @param int $depth
     * @return mixed
     */
    private function valueOf(IntrospectedSchema $schema, array $reference, mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_VALUE_DEPTH || !is_array($value)) {
            return $value;
        }

        $kind = $this->nodes->kindOf($reference);
        $wrapped = $this->nodes->ofType($reference);
        if ($kind === NodeReader::KIND_NON_NULL && $wrapped !== null) {
            return $this->valueOf($schema, $wrapped, $value, $depth);
        }
        if ($kind === NodeReader::KIND_LIST && $wrapped !== null) {
            return array_is_list($value)
                ? array_map(fn (mixed $item): mixed => $this->valueOf($schema, $wrapped, $item, $depth + 1), $value)
                : $this->valueOf($schema, $wrapped, $value, $depth + 1);
        }

        $type = $schema->getType($this->nodes->nameOf($reference));
        if ($type === null || $this->nodes->kindOf($type) !== NodeReader::KIND_INPUT_OBJECT) {
            return $value;
        }

        return $this->inputObjectValue($schema, $this->nodes->inputFieldsOf($type), $value, $depth + 1);
    }

    /**
     * An input object value: `{}` when empty, else each known field's value in its own type's form
     *
     * @param IntrospectedSchema $schema
     * @param list<InputValue> $inputFields
     * @param array<mixed> $value
     * @param int $depth
     * @return array<string, mixed>|\stdClass
     */
    private function inputObjectValue(
        IntrospectedSchema $schema,
        array $inputFields,
        array $value,
        int $depth
    ): array|\stdClass {
        if ($value === []) {
            return (object) [];
        }

        $types = array_column($inputFields, 'type', 'name');
        $object = [];
        foreach ($value as $name => $fieldValue) {
            $type = $types[$name] ?? null;
            $object[(string) $name] = $type === null
                ? $fieldValue
                : $this->valueOf($schema, $type, $fieldValue, $depth);
        }

        return $object;
    }

    /**
     * Refuse a field whose schema cannot be written into a document
     *
     * @param string $fieldName
     * @return never
     * @throws LocalizedException
     */
    private function refuseSchema(string $fieldName): never
    {
        throw new LocalizedException(__(
            'The GraphQL schema describes the field "%1" in a form this server cannot call.',
            $fieldName
        ));
    }
}
