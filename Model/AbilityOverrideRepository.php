<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Model\ResourceModel\AbilityOverride as AbilityOverrideResource;
use Hryvinskyi\McpServer\Model\ResourceModel\AbilityOverride\CollectionFactory;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The merchant's per-ability overrides, read from the override table once per request
 *
 * The exposure policy asks for the override of every ability it weighs, so the first read loads the whole table into
 * a map keyed by ability name and every later read is answered from it. A save or a delete drops the map, and the
 * next read loads the table again; the map is also dropped between requests.
 *
 * An override that neither denies nor asks for confirmation has nothing to store: saving one removes the ability's
 * row instead. An override whose ability name breaks the ability-name format is refused.
 */
class AbilityOverrideRepository implements AbilityOverrideRepositoryInterface, ResetAfterRequestInterface
{
    /**
     * @var array<string, AbilityOverrideInterface>|null
     */
    private ?array $overrides = null;

    /**
     * @param AbilityOverrideResource $resource
     * @param AbilityOverrideFactory $overrideFactory
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly AbilityOverrideResource $resource,
        private readonly AbilityOverrideFactory $overrideFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $ability): ?AbilityOverrideInterface
    {
        return $this->all()[$ability] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function save(AbilityOverrideInterface $override): void
    {
        $ability = $override->getAbility();
        if (!$override->isDenied() && !$override->needsConfirmation()) {
            try {
                $this->deleteRow($ability);
            } catch (\Exception $e) {
                throw new CouldNotSaveException(
                    __('The override of ability "%1" could not be removed.', $ability),
                    $e
                );
            }

            return;
        }

        if (strlen($ability) > AbilityInterface::NAME_MAX_LENGTH
            || preg_match(AbilityInterface::NAME_PATTERN, $ability) !== 1
        ) {
            throw new CouldNotSaveException(
                __('The override cannot be saved: "%1" is not an ability name.', $ability)
            );
        }

        $row = $this->overrideFactory->create();
        $row->setAbility($ability);
        $row->setDenied($override->isDenied());
        $row->setNeedsConfirmation($override->needsConfirmation());

        try {
            $this->resource->save($row);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('The override of ability "%1" could not be saved.', $ability),
                $e
            );
        } finally {
            $this->overrides = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $ability): void
    {
        try {
            $this->deleteRow($ability);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(
                __('The override of ability "%1" could not be removed.', $ability),
                $e
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $overrides = [];
        foreach ($this->collectionFactory->create()->getItems() as $row) {
            if ($row instanceof AbilityOverrideInterface) {
                $overrides[$row->getAbility()] = $row;
            }
        }

        return $this->overrides = $overrides;
    }

    /**
     * Forget the overrides read during the request that ended
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->overrides = null;
    }

    /**
     * Delete the ability's row; an ability without one is left as it is
     *
     * @param string $ability
     * @return void
     * @throws \Exception When the row cannot be deleted
     */
    private function deleteRow(string $ability): void
    {
        $row = $this->overrideFactory->create();
        $row->setAbility($ability);

        try {
            $this->resource->delete($row);
        } finally {
            $this->overrides = null;
        }
    }
}
