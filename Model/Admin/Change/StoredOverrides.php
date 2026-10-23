<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Change;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Changes one setting of each ability's stored override and keeps the other
 */
class StoredOverrides
{
    /**
     * @param AbilityOverrideRepositoryInterface $overrides
     * @param AbilityOverrideInterfaceFactory $overrideFactory
     */
    public function __construct(
        private readonly AbilityOverrideRepositoryInterface $overrides,
        private readonly AbilityOverrideInterfaceFactory $overrideFactory
    ) {
    }

    /**
     * Edit the override of each ability, starting from a blank one where none is stored, and save it
     *
     * @param list<string> $abilities
     * @param \Closure $edit Takes the override and returns it edited
     * @phpstan-param \Closure(AbilityOverrideInterface): AbilityOverrideInterface $edit
     * @return void
     * @throws CouldNotSaveException
     */
    public function edit(array $abilities, \Closure $edit): void
    {
        foreach ($abilities as $ability) {
            $override = $this->overrides->get($ability) ?? $this->overrideFactory->create()->setAbility($ability);
            $this->overrides->save($edit($override));
        }
    }
}
