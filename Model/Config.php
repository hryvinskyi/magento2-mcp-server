<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reads the MCP server settings from the default scope: the server is one for the whole installation
 */
class Config implements ConfigInterface
{
    public const XML_PATH_ENABLED = 'hryvinskyi_mcp_server/general/enabled';
    public const XML_PATH_READ_ONLY_MODE = 'hryvinskyi_mcp_server/general/read_only_mode';
    public const XML_PATH_EXPOSE_ANONYMOUS_ABILITIES = 'hryvinskyi_mcp_server/general/expose_anonymous_abilities';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * @inheritDoc
     */
    public function isReadOnlyMode(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_READ_ONLY_MODE);
    }

    /**
     * @inheritDoc
     */
    public function isAnonymousExposed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES);
    }
}
