<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\GraphQlInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\SchemaProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * The root fields of the store's GraphQL schema that are offered as abilities, with the schema they come from
 *
 * - Nothing is offered while the GraphQL abilities are switched off, and the schema is then not even read; nor while
 *   the schema cannot be read.
 * - Every field of the query type is offered, and every field of the mutation type while mutations are exposed.
 * - A field named in the exclusion list, as `<operation> <field>` (`query customer`), is left out silently.
 * - A field with an argument named `selection` is left out with a warning: that name carries the selection set of the
 *   result, so such an argument could not be sent.
 *
 * @phpstan-import-type Field from NodeReader
 */
class RootFieldLister
{
    /**
     * @param GraphQlConfigInterface $config
     * @param SchemaProviderInterface $schemaProvider
     * @param NodeReader $nodes
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GraphQlConfigInterface $config,
        private readonly SchemaProviderInterface $schemaProvider,
        private readonly NodeReader $nodes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The schema and its offered root fields, or null when none are offered
     *
     * @param list<string> $excludedFields Fields left out, as `<operation> <field>`
     * @return array{schema: IntrospectedSchema, fields: list<array{operation: Operation, field: Field}>}|null
     */
    public function exposedFields(array $excludedFields): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }
        $schema = $this->schemaProvider->schema();
        if ($schema === null) {
            return null;
        }

        $rootTypes = [Operation::Query->value => $schema->getQueryTypeName()];
        if ($this->config->isMutationExposed() && $schema->getMutationTypeName() !== '') {
            $rootTypes[Operation::Mutation->value] = $schema->getMutationTypeName();
        }

        $fields = [];
        foreach ($rootTypes as $operationValue => $typeName) {
            $operation = Operation::from($operationValue);
            foreach ($this->nodes->fieldsOf($schema->getType($typeName) ?? []) as $field) {
                if ($this->isOffered($operation, $field, $excludedFields)) {
                    $fields[] = ['operation' => $operation, 'field' => $field];
                }
            }
        }

        return ['schema' => $schema, 'fields' => $fields];
    }

    /**
     * Whether a root field is offered: not excluded, and no argument of it is named `selection`
     *
     * @param Operation $operation
     * @param Field $field
     * @param list<string> $excludedFields
     * @return bool
     */
    private function isOffered(Operation $operation, array $field, array $excludedFields): bool
    {
        $reference = $operation->value . ' ' . $field['name'];
        if (in_array($reference, $excludedFields, true)) {
            return false;
        }

        if (in_array(GraphQlInputSchemaBuilder::SELECTION, array_column($field['args'], 'name'), true)) {
            $this->logger->warning(sprintf(
                'MCP skips the GraphQL field %s: it takes an argument named "%s", the name that carries the '
                . 'selection set of the result.',
                $reference,
                GraphQlInputSchemaBuilder::SELECTION
            ));

            return false;
        }

        return true;
    }
}
