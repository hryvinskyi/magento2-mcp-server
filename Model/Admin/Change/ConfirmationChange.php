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
 * Makes each call of the abilities wait for a confirmation, or stops asking for one; the deny-list stays as it was
 */
class ConfirmationChange implements AbilityChangeInterface
{
    /**
     * @param StoredOverrides $overrides
     * @param bool $confirmed
     */
    public function __construct(
        private readonly StoredOverrides $overrides,
        private readonly bool $confirmed
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(array $abilities): void
    {
        $this->overrides->edit(
            $abilities,
            fn (AbilityOverrideInterface $override): AbilityOverrideInterface
                => $override->setNeedsConfirmation($this->confirmed)
        );
    }
}
