<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Magento\Framework\Exception\CouldNotSaveException;

/**
 * One change the catalog page offers for the abilities a merchant selects: deny, confirm, show personal data
 *
 * Each change is an item of the di.xml pool `changes` of ExposureChanges, under the name the page posts.
 */
interface AbilityChangeInterface
{
    /**
     * Apply the change to abilities that exist
     *
     * @param list<string> $abilities Ability names
     * @return void
     * @throws CouldNotSaveException
     */
    public function apply(array $abilities): void;
}
