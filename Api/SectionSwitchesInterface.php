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
 * The sections of the ability catalog the admin switched off
 *
 * A section is an area of the admin panel, named by its node of the ACL tree: Catalog, Sales, Customers, Stores ›
 * Inventory. An ability belongs to the sections of the ACL resources it needs, so a module that extends an area
 * lands in that area's section. While a section is off, no client may call its abilities.
 *
 * @api
 */
interface SectionSwitchesInterface
{
    /**
     * Whether the section is switched off
     *
     * @param string $section Section code
     * @return bool
     */
    public function isOff(string $section): bool;

    /**
     * Codes of the sections switched off
     *
     * @return list<string>
     */
    public function off(): array;

    /**
     * Switch the section off: its abilities are denied to every client
     *
     * @param string $section Section code
     * @return void
     * @throws CouldNotSaveException
     */
    public function switchOff(string $section): void;

    /**
     * Switch the section on again
     *
     * @param string $section Section code
     * @return void
     * @throws CouldNotSaveException
     */
    public function switchOn(string $section): void;
}
