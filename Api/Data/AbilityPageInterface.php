<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * One page of a catalog search: the abilities on the page and how many matched in all
 *
 * Create one through AbilityPageInterfaceFactory.
 *
 * @api
 */
interface AbilityPageInterface
{
    /**
     * How many abilities matched the search, on every page together
     *
     * @return int
     */
    public function getTotal(): int;

    /**
     * The abilities on this page, in the catalog's order
     *
     * @return list<AbilityInterface>
     */
    public function getItems(): array;
}
