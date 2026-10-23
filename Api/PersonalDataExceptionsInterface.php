<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Magento\Framework\Exception\CouldNotSaveException;

/**
 * The abilities the admin chose to show personal data to, while hiding is on for the rest
 *
 * @api
 */
interface PersonalDataExceptionsInterface
{
    /**
     * Names of the abilities whose results keep their personal data
     *
     * @return list<string>
     */
    public function shown(): array;

    /**
     * Let these abilities' results keep their personal data
     *
     * @param list<string> $abilities Ability names
     * @return void
     * @throws CouldNotSaveException
     */
    public function show(array $abilities): void;

    /**
     * Hide the personal data in these abilities' results again
     *
     * @param list<string> $abilities Ability names
     * @return void
     * @throws CouldNotSaveException
     */
    public function hide(array $abilities): void;
}
