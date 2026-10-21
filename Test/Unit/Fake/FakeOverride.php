<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;

/**
 * An override held in memory
 */
class FakeOverride implements AbilityOverrideInterface
{
    /**
     * @param string $ability
     * @param bool $denied
     * @param bool $needsConfirmation
     */
    public function __construct(
        private string $ability,
        private bool $denied = false,
        private bool $needsConfirmation = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAbility(): string
    {
        return $this->ability;
    }

    /**
     * @inheritDoc
     */
    public function setAbility(string $ability): self
    {
        $this->ability = $ability;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * @inheritDoc
     */
    public function setDenied(bool $denied): self
    {
        $this->denied = $denied;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function needsConfirmation(): bool
    {
        return $this->needsConfirmation;
    }

    /**
     * @inheritDoc
     */
    public function setNeedsConfirmation(bool $needsConfirmation): self
    {
        $this->needsConfirmation = $needsConfirmation;

        return $this;
    }
}
