<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Psr\Log\LoggerInterface;

/**
 * The abilities of the GraphQL source: one per root field, and the helper that describes a type
 *
 * - A root field is named `graphql.<operation>.<field in snake case>` (`graphql.query.products`,
 *   `graphql.mutation.create_empty_cart`); snake case splits camel case and acronyms alike (`getPaymentSDK` is
 *   `get_payment_sdk`).
 * - Its title is the first sentence of the field's description, else the field name in words; its description is the
 *   whole description, with the reason when the field is deprecated.
 * - Its category is `GraphQL`; its access is `read` for a query and `write` for a mutation; its source reference is
 *   `<operation> <field>` (`query products`).
 * - Every GraphQL ability runs as a guest, so its only ACL resource is `anonymous`.
 * - The helper `graphql.schema.describe_type` reads the schema; its source reference is `schema __type`.
 *
 * Abilities are built from the schema's own names and texts as scalars, never from an array of the introspection. A
 * field whose name is not a GraphQL name, or gives no valid ability name, is logged as a warning and gives none.
 *
 * @phpstan-import-type Field from NodeReader
 */
class GraphQlAbilityBuilder
{
    /**
     * Category of every GraphQL ability
     */
    public const CATEGORY = 'GraphQL';

    /**
     * Name of the helper ability that describes a type of the schema
     */
    public const DESCRIBE_TYPE = 'graphql.schema.describe_type';

    /**
     * The only ACL resource of every GraphQL ability: they run as a guest
     */
    public const ACL_RESOURCE = 'anonymous';

    private const NAME_PREFIX = 'graphql.';

    /**
     * @param AbilityInterfaceFactory $abilityFactory
     * @param NodeReader $nodes
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AbilityInterfaceFactory $abilityFactory,
        private readonly NodeReader $nodes,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The ability a root field offers, or null (logged) for a field that cannot be one
     *
     * @param Operation $operation
     * @param Field $field
     * @param string $source Code of the ability source the ability belongs to
     * @return AbilityInterface|null
     */
    public function forField(Operation $operation, array $field, string $source): ?AbilityInterface
    {
        $reference = $operation->value . ' ' . $field['name'];
        if (!$this->nodes->isName($field['name'])) {
            $this->logSkip($reference, 'its name is not a GraphQL name');

            return null;
        }

        $words = $this->snake($field['name']);

        try {
            return $this->abilityFactory->create([
                'name' => self::NAME_PREFIX . $operation->value . '.' . $words,
                'title' => $this->titleOf($field['description'], $words),
                'category' => self::CATEGORY,
                'source' => $source,
                'sourceReference' => $reference,
                'access' => $operation->access(),
                'aclResources' => [self::ACL_RESOURCE],
                'description' => $this->descriptionOf($field),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->logSkip($reference, $exception->getMessage());

            return null;
        }
    }

    /**
     * The helper ability that describes a type of the schema
     *
     * @param string $source Code of the ability source the ability belongs to
     * @return AbilityInterface
     */
    public function forTypeDescription(string $source): AbilityInterface
    {
        return $this->abilityFactory->create([
            'name' => self::DESCRIBE_TYPE,
            'title' => 'Describe a GraphQL type',
            'category' => self::CATEGORY,
            'source' => $source,
            'sourceReference' => 'schema __type',
            'access' => AbilityAccess::Read,
            'aclResources' => [self::ACL_RESOURCE],
            'description' => 'The kind, fields with their arguments, input fields and enum values of a type of the '
                . 'store\'s GraphQL schema, to write the selection set or the input objects of a GraphQL ability.',
        ]);
    }

    /**
     * A GraphQL name in lowercase snake case, splitting camel case and acronyms (`getPaymentSDK` → `get_payment_sdk`)
     *
     * @param string $name
     * @return string
     */
    private function snake(string $name): string
    {
        $split = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $name);

        return strtolower($split ?? $name);
    }

    /**
     * The first sentence of the description, else the field name in words
     *
     * @param string $description
     * @param string $words The field name in snake case
     * @return string
     */
    private function titleOf(string $description, string $words): string
    {
        $firstLine = trim(strtok($description, "\r\n") ?: '');
        $sentence = preg_split('/(?<=\.)\s/', $firstLine, 2)[0] ?? '';
        $title = rtrim(trim($sentence), '.');

        return $title !== '' ? $title : ucfirst(trim(str_replace('_', ' ', $words)));
    }

    /**
     * The field's description, followed by why it is deprecated when it is
     *
     * @param Field $field
     * @return string
     */
    private function descriptionOf(array $field): string
    {
        $description = trim($field['description']);
        if (!$field['isDeprecated']) {
            return $description;
        }

        $reason = $field['deprecationReason'] !== '' ? $field['deprecationReason'] : 'No reason given.';

        return trim($description . "\nDeprecated: " . $reason);
    }

    /**
     * Log why a root field gives no ability
     *
     * @param string $reference
     * @param string $reason
     * @return void
     */
    private function logSkip(string $reference, string $reason): void
    {
        $this->logger->warning(sprintf('MCP skips the GraphQL field %s: %s.', $reference, rtrim($reason, '.')));
    }
}
