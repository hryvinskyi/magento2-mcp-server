<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

/**
 * Whether the personal data in an ability's results is kept from MCP clients
 *
 * An MCP client hands what it receives to its AI provider. While hiding is on, every value under a key the store
 * lists as personal (an email, a name, a phone number, an address line) reaches the client as HIDDEN, unless the
 * admin chose to show personal data to that ability.
 *
 * @api
 */
interface PersonalDataPolicyInterface
{
    /**
     * What a client receives in place of a hidden value
     */
    public const HIDDEN = '[personal data]';

    /**
     * Whether personal data is hidden from clients at all; on after install
     *
     * @return bool
     */
    public function isHidingOn(): bool;

    /**
     * Whether the personal data in this ability's results reaches clients hidden
     *
     * @param string $ability Ability name
     * @return bool
     */
    public function hidesFor(string $ability): bool;
}
