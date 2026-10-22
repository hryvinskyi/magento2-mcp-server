<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;

/**
 * `discover_abilities`: one page of the abilities matching a search, with what each does to the data and whether the
 * store lets clients call it
 *
 * Every ability is listed, whatever the caller's ACL: whether the caller's role may call one is get_ability_info's
 * answer.
 */
class DiscoverAbilities implements ToolInterface
{
    public const NAME = 'discover_abilities';

    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    /**
     * @param AbilityCatalogInterface $catalog
     * @param ExposurePolicyInterface $exposurePolicy
     * @param ArgumentReader $arguments
     * @param ToolResults $toolResults
     */
    public function __construct(
        private readonly AbilityCatalogInterface $catalog,
        private readonly ExposurePolicyInterface $exposurePolicy,
        private readonly ArgumentReader $arguments,
        private readonly ToolResults $toolResults
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Search the abilities of this Magento store: the operations of its Web API that execute_ability runs. '
            . 'The query is matched, case-insensitively, against ability names, titles and categories; results are '
            . 'sorted by name and paged. Each ability says what it does to data (read, write or delete) and its '
            . 'exposure: exposed, needs_confirmation, read_only_blocked or denied.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Words to find in the ability name, title or category; empty lists every ability.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Only abilities of this category: a Magento module name such as '
                        . 'Magento_Catalog, or GraphQL.',
                ],
                'access' => [
                    'type' => 'string',
                    'enum' => array_map(
                        static fn (AbilityAccess $access): string => $access->value,
                        AbilityAccess::cases()
                    ),
                    'description' => 'Only abilities with this access.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'How many abilities to return.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'How many matching abilities to skip.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        return [
            'title' => 'Discover abilities',
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function call(array $arguments, CallContext $context): array
    {
        $category = $this->arguments->optionalString($arguments, 'category');
        $page = $this->catalog->search(
            $this->arguments->optionalString($arguments, 'query') ?? '',
            $category === '' ? null : $category,
            $this->access($this->arguments->optionalString($arguments, 'access')),
            $this->arguments->integer($arguments, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT),
            $this->arguments->integer($arguments, 'offset', 0, 0, PHP_INT_MAX)
        );

        return $this->toolResults->success([
            'total' => $page->getTotal(),
            'abilities' => array_map(fn (AbilityInterface $ability): array => [
                'name' => $ability->getName(),
                'title' => $ability->getTitle(),
                'category' => $ability->getCategory(),
                'access' => $ability->getAccess()->value,
                'exposure' => $this->exposurePolicy->exposureOf($ability)->value,
            ], $page->getItems()),
        ]);
    }

    /**
     * The access filter the argument names, or null for none
     *
     * @param string|null $code
     * @return AbilityAccess|null
     * @throws InvalidToolArgumentException When the argument names no access
     */
    private function access(?string $code): ?AbilityAccess
    {
        if ($code === null || $code === '') {
            return null;
        }

        return AbilityAccess::tryFrom($code) ?? throw new InvalidToolArgumentException(
            __('The argument "access" must be one of: read, write, delete.')->render()
        );
    }
}
