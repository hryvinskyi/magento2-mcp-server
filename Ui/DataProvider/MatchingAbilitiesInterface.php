<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\DataProvider;

/**
 * The abilities a listing's search and filters select, on every page at once
 */
interface MatchingAbilitiesInterface
{
    /**
     * The names of every ability the search and filters select, whatever the page
     *
     * @return list<string>
     */
    public function matchingNames(): array;
}
