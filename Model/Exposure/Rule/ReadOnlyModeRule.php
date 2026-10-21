<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Exposure\Rule;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\ExposureRuleInterface;

/**
 * Blocks every ability that does more than read while read-only mode is on
 *
 * An ability that only reads is left alone, whatever the mode.
 */
class ReadOnlyModeRule implements ExposureRuleInterface
{
    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        if ($ability->getAccess()->isReadOnly()) {
            return null;
        }

        return $this->config->isReadOnlyMode() ? Exposure::ReadOnlyBlocked : null;
    }
}
