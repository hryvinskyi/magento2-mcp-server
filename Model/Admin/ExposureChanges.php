<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The merchant's changes to many abilities at once: the deny-list, confirmations, personal data
 *
 * Each change is an item of the di.xml pool `changes`, under the name the catalog page posts. A name no source
 * offers is skipped, so a stale selection never stores anything for an ability that does not exist. Each change
 * applies at once: the next call of the ability already sees it.
 */
class ExposureChanges
{
    /**
     * @param AbilityCatalogInterface $catalog
     * @param array<string,AbilityChangeInterface> $changes
     */
    public function __construct(
        private readonly AbilityCatalogInterface $catalog,
        private readonly array $changes = []
    ) {
    }

    /**
     * Whether a change of that name exists
     *
     * @param string $change
     * @return bool
     */
    public function exists(string $change): bool
    {
        return isset($this->changes[$change]);
    }

    /**
     * Apply the change to the named abilities that exist
     *
     * @param string $change
     * @param string[] $names
     * @return int How many abilities were changed
     * @throws \InvalidArgumentException When no change has that name
     * @throws CouldNotSaveException
     */
    public function apply(string $change, array $names): int
    {
        $apply = $this->changes[$change] ?? throw new \InvalidArgumentException(
            sprintf('No exposure change is called "%s".', $change)
        );

        $existing = [];
        foreach (array_unique($names) as $name) {
            try {
                $existing[] = $this->catalog->get($name)->getName();
            } catch (NoSuchEntityException) {
                continue;
            }
        }
        if ($existing !== []) {
            $apply->apply($existing);
        }

        return count($existing);
    }
}
