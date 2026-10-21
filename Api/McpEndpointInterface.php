<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * The Web API service the MCP route is declared on
 *
 * Magento's REST stack needs a service behind every route to authenticate the caller and check the route's ACL.
 * The MCP messages themselves are answered before this service is reached; the service only runs when nothing
 * else took the request.
 *
 * @api
 */
interface McpEndpointInterface
{
    /**
     * Refuse the request: MCP messages are not answered here
     *
     * @return string
     * @throws LocalizedException Always
     */
    public function handle(): string;
}
