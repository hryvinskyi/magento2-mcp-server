<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Every ability of every registered source, by name
 *
 * The catalog lists abilities whatever the caller's ACL: a caller can always learn that an ability exists and what
 * it needs, and isAllowed() says whether this caller may call it.
 *
 * @api
 */
interface AbilityCatalogInterface
{
    /**
     * The ability with this name
     *
     * @param string $name
     * @return AbilityInterface
     * @throws NoSuchEntityException When no source offers an ability of that name
     */
    public function get(string $name): AbilityInterface;

    /**
     * One page of the abilities that match, sorted by name
     *
     * @param string $query Words to find, case-insensitive, in the name, title or category; '' matches every ability
     * @param string|null $category Only abilities of this category, or null for every category
     * @param AbilityAccess|null $access Only abilities with this access, or null for every access
     * @param int $limit Size of the page, at least 1
     * @param int $offset How many matches to skip, at least 0
     * @return AbilityPageInterface
     * @throws \InvalidArgumentException When the limit is below 1 or the offset below 0
     */
    public function search(
        string $query,
        ?string $category,
        ?AbilityAccess $access,
        int $limit,
        int $offset
    ): AbilityPageInterface;

    /**
     * JSON Schema of the ability's arguments, from the source that offers it
     *
     * @param AbilityInterface $ability
     * @return array<string, mixed>
     * @throws NoSuchEntityException When no source offers the ability
     */
    public function schemaOf(AbilityInterface $ability): array;

    /**
     * Whether the current caller's ACL lets it call the ability, as the source that offers it decides
     *
     * @param AbilityInterface $ability
     * @return bool
     * @throws NoSuchEntityException When no source offers the ability
     */
    public function isAllowed(AbilityInterface $ability): bool;
}
