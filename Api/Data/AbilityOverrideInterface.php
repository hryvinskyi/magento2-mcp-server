<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * A merchant's stored setting for one ability: put it on the deny-list, ask for confirmation, or both
 *
 * @api
 */
interface AbilityOverrideInterface
{
    public const ABILITY = 'ability';
    public const DENY = 'deny';
    public const CONFIRM = 'confirm';
    public const UPDATED_AT = 'updated_at';

    /**
     * Name of the ability the override applies to
     *
     * @return string
     */
    public function getAbility(): string;

    /**
     * Set the name of the ability the override applies to
     *
     * @param string $ability
     * @return $this
     */
    public function setAbility(string $ability): self;

    /**
     * Whether the ability is on the deny-list
     *
     * @return bool
     */
    public function isDenied(): bool;

    /**
     * Put the ability on the deny-list, or take it off
     *
     * @param bool $denied
     * @return $this
     */
    public function setDenied(bool $denied): self;

    /**
     * Whether a client must confirm each call of the ability
     *
     * @return bool
     */
    public function needsConfirmation(): bool;

    /**
     * Require a confirmed call of the ability, or stop requiring it
     *
     * @param bool $needsConfirmation
     * @return $this
     */
    public function setNeedsConfirmation(bool $needsConfirmation): self;
}
