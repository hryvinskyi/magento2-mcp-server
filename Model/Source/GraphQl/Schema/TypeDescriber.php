<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

use Magento\Framework\Exception\LocalizedException;

/**
 * Describes one type of the GraphQL schema, so a model can write a selection set or an input object
 *
 * The answer holds the type's name and kind and, where the type has them and they are not empty: its description,
 * its fields (name, type as GraphQL writes it, arguments), its input fields, its enum values, the interfaces it
 * implements and the types an interface or union stands for. A deprecated field or enum value carries the reason.
 *
 * It answers from the schema the source already holds; it sends nothing to the endpoint.
 *
 * @phpstan-import-type InputValue from NodeReader
 */
class TypeDescriber
{
    /**
     * Name of the argument naming the type
     */
    public const NAME_ARGUMENT = 'name';

    /**
     * @param NodeReader $nodes
     */
    public function __construct(
        private readonly NodeReader $nodes
    ) {
    }

    /**
     * The JSON Schema of the describing ability's arguments
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                self::NAME_ARGUMENT => [
                    'type' => 'string',
                    'description' => 'Name of the GraphQL type, such as Products or ProductAttributeFilterInput.',
                ],
            ],
            'required' => [self::NAME_ARGUMENT],
        ];
    }

    /**
     * The description of the type the arguments name
     *
     * @param IntrospectedSchema $schema
     * @param array<string,mixed> $arguments
     * @return array<string, mixed>
     * @throws LocalizedException When no type is named, or the schema has no type of that name
     */
    public function describe(IntrospectedSchema $schema, array $arguments): array
    {
        $name = $arguments[self::NAME_ARGUMENT] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new LocalizedException(__('Name the GraphQL type to describe in the argument "name".'));
        }

        $name = trim($name);
        $type = $schema->getType($name);
        if ($type === null) {
            throw new LocalizedException(__('The GraphQL schema has no type named "%1".', $name));
        }

        return $this->withoutEmpty([
            'name' => $name,
            'kind' => $this->nodes->kindOf($type),
            'description' => $this->nodes->descriptionOf($type),
            'fields' => $this->fields($type),
            'inputFields' => array_map(
                fn (array $inputValue): array => $this->inputValue($inputValue),
                $this->nodes->inputFieldsOf($type)
            ),
            'enumValues' => $this->enumValues($type),
            'interfaces' => $this->nodes->typeNames($type['interfaces'] ?? null),
            'possibleTypes' => $this->nodes->typeNames($type['possibleTypes'] ?? null),
        ]);
    }

    /**
     * The fields of the type, each with its arguments
     *
     * @param array<mixed> $type
     * @return list<array<string, mixed>>
     */
    private function fields(array $type): array
    {
        $fields = [];
        foreach ($this->nodes->fieldsOf($type) as $field) {
            $fields[] = $this->withoutEmpty([
                'name' => $field['name'],
                'type' => $this->nodes->typeString($field['type']),
                'description' => $field['description'],
                'args' => array_map(
                    fn (array $inputValue): array => $this->inputValue($inputValue),
                    $field['args']
                ),
                'deprecated' => $this->deprecation($field['isDeprecated'], $field['deprecationReason']),
            ]);
        }

        return $fields;
    }

    /**
     * The values of an enum type
     *
     * @param array<mixed> $type
     * @return list<array<string, mixed>>
     */
    private function enumValues(array $type): array
    {
        $values = [];
        foreach ($this->nodes->enumValuesOf($type) as $value) {
            $values[] = $this->withoutEmpty([
                'name' => $value['name'],
                'description' => $value['description'],
                'deprecated' => $this->deprecation($value['isDeprecated'], $value['deprecationReason']),
            ]);
        }

        return $values;
    }

    /**
     * An argument or input field: name, type, default value and description
     *
     * @param InputValue $inputValue
     * @return array<string, mixed>
     */
    private function inputValue(array $inputValue): array
    {
        return $this->withoutEmpty([
            'name' => $inputValue['name'],
            'type' => $this->nodes->typeString($inputValue['type']),
            'defaultValue' => $inputValue['defaultValue'] ?? '',
            'description' => $inputValue['description'],
        ]);
    }

    /**
     * Why a field or value is deprecated; '' when it is not
     *
     * @param bool $isDeprecated
     * @param string $reason
     * @return string
     */
    private function deprecation(bool $isDeprecated, string $reason): string
    {
        if (!$isDeprecated) {
            return '';
        }

        return $reason !== '' ? $reason : 'Deprecated.';
    }

    /**
     * The members that hold something: no '' and no empty list, apart from the arguments of a field
     *
     * @param array<string,mixed> $members
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $members): array
    {
        return array_filter(
            $members,
            static fn (mixed $value, string $key): bool => $key === 'args' || ($value !== '' && $value !== []),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
