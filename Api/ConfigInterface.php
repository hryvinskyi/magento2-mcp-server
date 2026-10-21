<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

/**
 * MCP server settings, read at the default scope
 *
 * @api
 */
interface ConfigInterface
{
    /**
     * Whether the MCP endpoint answers at all; off after install
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Whether only abilities that read may be called; on after install
     *
     * @return bool
     */
    public function isReadOnlyMode(): bool;

    /**
     * Whether abilities anyone may call (ACL resource `anonymous` alone) are exposed to MCP clients; off after install
     *
     * @return bool
     */
    public function isAnonymousExposed(): bool;
}
