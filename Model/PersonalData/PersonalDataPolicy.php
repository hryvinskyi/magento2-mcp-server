<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\PersonalData;

use Hryvinskyi\McpServer\Api\PersonalDataExceptionsInterface;
use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Hiding follows the setting Hide Personal Data, with the abilities the admin chose to show it to left out
 */
class PersonalDataPolicy implements PersonalDataPolicyInterface
{
    public const XML_PATH_HIDE = 'hryvinskyi_mcp_server/personal_data/hide';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param PersonalDataExceptionsInterface $exceptions
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PersonalDataExceptionsInterface $exceptions
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isHidingOn(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_HIDE);
    }

    /**
     * @inheritDoc
     */
    public function hidesFor(string $ability): bool
    {
        return $this->isHidingOn() && !in_array($ability, $this->exceptions->shown(), true);
    }
}
