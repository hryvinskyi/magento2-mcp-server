<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Exposure\Rule;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\ExposureRuleInterface;

/**
 * Denies an ability the merchant put on the deny-list through its stored override
 */
class DenyListRule implements ExposureRuleInterface
{
    /**
     * @param AbilityOverrideRepositoryInterface $overrides
     */
    public function __construct(
        private readonly AbilityOverrideRepositoryInterface $overrides
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        $override = $this->overrides->get($ability->getName());

        return $override !== null && $override->isDenied() ? Exposure::Denied : null;
    }
}
