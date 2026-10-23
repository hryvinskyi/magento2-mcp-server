<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Change;

use Hryvinskyi\McpServer\Api\PersonalDataExceptionsInterface;
use Hryvinskyi\McpServer\Model\Admin\AbilityChangeInterface;

/**
 * Hides the personal data in the abilities' results again
 */
class HidePersonalDataChange implements AbilityChangeInterface
{
    /**
     * @param PersonalDataExceptionsInterface $exceptions
     */
    public function __construct(
        private readonly PersonalDataExceptionsInterface $exceptions
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(array $abilities): void
    {
        $this->exceptions->hide($abilities);
    }
}
