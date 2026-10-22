<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\FieldCaller;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\GraphQlInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\TypeDescriber;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Webapi\Authorization;
use Psr\Log\LoggerInterface;

/**
 * Ability source `graphql`: the root fields of the store's own GraphQL schema, run as a guest
 *
 * - One ability per field of the query type, and per field of the mutation type while mutations are exposed (see
 *   RootFieldLister), plus `graphql.schema.describe_type`, which describes a type of the schema. Nothing is listed
 *   while the GraphQL abilities are off or the schema cannot be read.
 * - The di.xml argument `excludedFields` names fields never offered, as `<operation> <field>` (`query customer`): the
 *   fields that answer only a signed-in customer, which a guest always gets an authorization error from.
 * - Two fields whose names give the same ability name: the first keeps it, the other is skipped with a warning.
 * - The abilities are listed once per request and kept until the request ends.
 * - The input schema is built from the field's introspected arguments, with the `selection` argument for a field
 *   whose result is not a scalar or an enum value.
 * - The ACL check asks Magento's Web API authorization for `anonymous`, which Magento's Web API areas (REST and SOAP)
 *   let through; outside those areas a GraphQL ability is denied.
 * - A call sends one operation to the store's GraphQL endpoint (FieldCaller); describe_type answers from the schema.
 */
class GraphQlAbilitySource implements AbilitySourceInterface, ResetAfterRequestInterface
{
    /**
     * Code of the source
     */
    public const CODE = 'graphql';

    /**
     * @var array<string, array{
     *     ability: AbilityInterface,
     *     inputSchema: \Closure(): array<string, mixed>,
     *     invoke: \Closure(array<string, mixed>): mixed
     * }>|null
     */
    private ?array $entries = null;

    /**
     * @param RootFieldLister $rootFields
     * @param GraphQlAbilityBuilder $abilityBuilder
     * @param GraphQlInputSchemaBuilder $inputSchemaBuilder
     * @param FieldCaller $fieldCaller
     * @param TypeDescriber $typeDescriber
     * @param Authorization $authorization
     * @param LoggerInterface $logger
     * @param array<string,string> $excludedFields Fields never offered, as `<operation> <field>`, keyed by a name
     */
    public function __construct(
        private readonly RootFieldLister $rootFields,
        private readonly GraphQlAbilityBuilder $abilityBuilder,
        private readonly GraphQlInputSchemaBuilder $inputSchemaBuilder,
        private readonly FieldCaller $fieldCaller,
        private readonly TypeDescriber $typeDescriber,
        private readonly Authorization $authorization,
        private readonly LoggerInterface $logger,
        private readonly array $excludedFields = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function abilities(): iterable
    {
        foreach ($this->entries() as $entry) {
            yield $entry['ability'];
        }
    }

    /**
     * @inheritDoc
     */
    public function inputSchema(AbilityInterface $ability): array
    {
        return ($this->entryOf($ability)['inputSchema'])();
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(AbilityInterface $ability): bool
    {
        return $this->authorization->isAllowed($this->entryOf($ability)['ability']->getAclResources());
    }

    /**
     * @inheritDoc
     */
    public function invoke(AbilityInterface $ability, array $arguments): mixed
    {
        return ($this->entryOf($ability)['invoke'])($arguments);
    }

    /**
     * Forget the abilities listed during the request that ended
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->entries = null;
    }

    /**
     * The entry behind one of the source's abilities
     *
     * @param AbilityInterface $ability
     * @return array{
     *     ability: AbilityInterface,
     *     inputSchema: \Closure(): array<string, mixed>,
     *     invoke: \Closure(array<string, mixed>): mixed
     * }
     * @throws NoSuchEntityException When the source does not offer the ability
     */
    private function entryOf(AbilityInterface $ability): array
    {
        $entry = $this->entries()[$ability->getName()] ?? null;
        if ($entry === null || $ability->getSource() !== self::CODE) {
            throw new NoSuchEntityException(
                __('The store\'s GraphQL schema offers no ability named "%1".', $ability->getName())
            );
        }

        return $entry;
    }

    /**
     * Ability, schema and call by ability name, built on the first read of the request
     *
     * @return array<string, array{
     *     ability: AbilityInterface,
     *     inputSchema: \Closure(): array<string, mixed>,
     *     invoke: \Closure(array<string, mixed>): mixed
     * }>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $exposed = $this->rootFields->exposedFields(array_values($this->excludedFields));
        if ($exposed === null) {
            return $this->entries = [];
        }

        $schema = $exposed['schema'];
        $entries = [];
        foreach ($exposed['fields'] as ['operation' => $operation, 'field' => $field]) {
            $ability = $this->abilityBuilder->forField($operation, $field, self::CODE);
            if ($ability === null) {
                continue;
            }

            $taken = $entries[$ability->getName()]['ability'] ?? null;
            if ($taken !== null) {
                $this->logger->warning(sprintf(
                    'MCP skips the GraphQL field %s: the field %s already offers the ability "%s".',
                    $ability->getSourceReference(),
                    $taken->getSourceReference(),
                    $ability->getName()
                ));
                continue;
            }

            $name = $ability->getName();
            $entries[$name] = $this->entry(
                $ability,
                fn (): array => $this->inputSchemaBuilder->build($schema, $field),
                fn (array $arguments): mixed => $this->fieldCaller->call($schema, $operation, $field, $name, $arguments)
            );
        }

        $describe = $this->abilityBuilder->forTypeDescription(self::CODE);
        $entries[$describe->getName()] = $this->entry(
            $describe,
            fn (): array => $this->typeDescriber->inputSchema(),
            fn (array $arguments): mixed => $this->typeDescriber->describe($schema, $arguments)
        );

        return $this->entries = $entries;
    }

    /**
     * One entry of the index: the ability, how its input schema is built and how it is called
     *
     * @param AbilityInterface $ability
     * @param \Closure():array<string,mixed> $inputSchema
     * @param \Closure(array<string,mixed>):mixed $invoke
     * @return array{
     *     ability: AbilityInterface,
     *     inputSchema: \Closure(): array<string, mixed>,
     *     invoke: \Closure(array<string, mixed>): mixed
     * }
     */
    private function entry(AbilityInterface $ability, \Closure $inputSchema, \Closure $invoke): array
    {
        return ['ability' => $ability, 'inputSchema' => $inputSchema, 'invoke' => $invoke];
    }
}
