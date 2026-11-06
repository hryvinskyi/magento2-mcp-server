<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

/**
 * What every MCP client is configured with to reach this server: the name it lists the server under and the URL
 *
 * Whatever sets a client up reads both from here, so every client names and reaches the server the same way.
 */
interface ClientConnectionInterface
{
    /**
     * The name a client lists this server under
     *
     * @return string
     */
    public function serverName(): string;

    /**
     * The URL clients send MCP messages to
     *
     * It is the default store view's secure web base URL followed by the MCP route's REST path.
     *
     * @return string
     */
    public function endpointUrl(): string;
}
