<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;

/**
 * Overrides held in memory, with the repository's rule that an override setting neither flag is not kept
 */
class InMemoryOverrideRepository implements AbilityOverrideRepositoryInterface
{
    /**
     * @var array<string, AbilityOverrideInterface>
     */
    private array $overrides = [];

    /**
     * @param list<AbilityOverrideInterface> $overrides
     */
    public function __construct(array $overrides = [])
    {
        foreach ($overrides as $override) {
            $this->save($override);
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $ability): ?AbilityOverrideInterface
    {
        return $this->overrides[$ability] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function save(AbilityOverrideInterface $override): void
    {
        if (!$override->isDenied() && !$override->needsConfirmation()) {
            $this->delete($override->getAbility());

            return;
        }

        $this->overrides[$override->getAbility()] = $override;
    }

    /**
     * @inheritDoc
     */
    public function delete(string $ability): void
    {
        unset($this->overrides[$ability]);
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        return $this->overrides;
    }
}
