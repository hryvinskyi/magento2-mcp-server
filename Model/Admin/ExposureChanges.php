<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterfaceFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The merchant's changes to the stored deny-list and confirmations, applied to many abilities at once
 *
 * A change sets one of the two settings of an ability's override and keeps the other. A name no source offers is
 * skipped, so a stale selection never stores an override for an ability that does not exist. Each change applies at
 * once: the next call of the ability already sees it.
 */
class ExposureChanges
{
    /**
     * Each change: the setting it sets and the value it sets it to
     */
    private const CHANGES = [
        'deny' => ['setting' => 'deny', 'on' => true],
        'allow' => ['setting' => 'deny', 'on' => false],
        'confirm' => ['setting' => 'confirm', 'on' => true],
        'unconfirm' => ['setting' => 'confirm', 'on' => false],
    ];

    /**
     * @param AbilityCatalogInterface $catalog
     * @param AbilityOverrideRepositoryInterface $overrides
     * @param AbilityOverrideInterfaceFactory $overrideFactory
     */
    public function __construct(
        private readonly AbilityCatalogInterface $catalog,
        private readonly AbilityOverrideRepositoryInterface $overrides,
        private readonly AbilityOverrideInterfaceFactory $overrideFactory
    ) {
    }

    /**
     * Whether a change of this code exists
     *
     * @param string $change
     * @return bool
     */
    public function exists(string $change): bool
    {
        return isset(self::CHANGES[$change]);
    }

    /**
     * Apply a change to every named ability; how many abilities it was applied to
     *
     * @param string $change "deny", "allow", "confirm" or "unconfirm"
     * @param list<string> $names
     * @return int
     * @throws \InvalidArgumentException When no change has that code
     * @throws CouldNotSaveException
     */
    public function apply(string $change, array $names): int
    {
        $rule = self::CHANGES[$change] ?? throw new \InvalidArgumentException(
            sprintf('No exposure change is called "%s".', $change)
        );

        $applied = 0;
        foreach (array_unique($names) as $name) {
            try {
                $this->catalog->get($name);
            } catch (NoSuchEntityException) {
                continue;
            }

            $override = $this->overrides->get($name)
                ?? $this->overrideFactory->create()->setAbility($name);
            if ($rule['setting'] === 'deny') {
                $override->setDenied($rule['on']);
            } else {
                $override->setNeedsConfirmation($rule['on']);
            }
            $this->overrides->save($override);
            $applied++;
        }

        return $applied;
    }
}
