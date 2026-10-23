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
 * Lets the abilities' results keep their personal data
 */
class ShowPersonalDataChange implements AbilityChangeInterface
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
        $this->exceptions->show($abilities);
    }
}
