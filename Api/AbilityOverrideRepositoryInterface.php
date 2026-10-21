<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * The merchant's stored per-ability overrides
 *
 * An override that neither denies nor asks for confirmation is not stored: saving one removes the ability's row.
 *
 * @api
 */
interface AbilityOverrideRepositoryInterface
{
    /**
     * The override of this ability, or null when it has none
     *
     * @param string $ability
     * @return AbilityOverrideInterface|null
     */
    public function get(string $ability): ?AbilityOverrideInterface;

    /**
     * Store the override, or remove it when it neither denies nor asks for confirmation
     *
     * @param AbilityOverrideInterface $override
     * @return void
     * @throws CouldNotSaveException
     */
    public function save(AbilityOverrideInterface $override): void;

    /**
     * Remove the override of this ability; an ability without one is left as it is
     *
     * @param string $ability
     * @return void
     * @throws CouldNotDeleteException
     */
    public function delete(string $ability): void;

    /**
     * Every stored override, keyed by ability name
     *
     * @return array<string, AbilityOverrideInterface>
     */
    public function all(): array;
}
