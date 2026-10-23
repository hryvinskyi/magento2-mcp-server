<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Change;

use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Model\Admin\AbilityChangeInterface;

/**
 * Puts the abilities on the stored deny-list, or takes them off it; their confirmation stays as it was
 */
class DenyListChange implements AbilityChangeInterface
{
    /**
     * @param StoredOverrides $overrides
     * @param bool $denied
     */
    public function __construct(
        private readonly StoredOverrides $overrides,
        private readonly bool $denied
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(array $abilities): void
    {
        $this->overrides->edit(
            $abilities,
            fn (AbilityOverrideInterface $override): AbilityOverrideInterface => $override->setDenied($this->denied)
        );
    }
}
