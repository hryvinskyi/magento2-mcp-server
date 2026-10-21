<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model;

use Hryvinskyi\McpServer\Api\McpEndpointInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * The service behind the MCP route, which refuses every request it is asked to handle
 *
 * Reaching it means the request was routed to the service instead of being answered as an MCP message.
 */
class McpEndpointPlaceholder implements McpEndpointInterface
{
    /**
     * @inheritDoc
     */
    public function handle(): string
    {
        throw new LocalizedException(
            __('MCP messages are answered by the MCP endpoint, which did not take this request.')
        );
    }
}
