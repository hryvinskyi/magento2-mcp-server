<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Store;

/**
 * The time zone each store view of the store runs in
 */
interface StoreTimeZonesInterface
{
    /**
     * The IANA time zone of each store view, keyed by the store view's code; the admin store view is not one of them
     *
     * @return array<string, string>
     */
    public function zones(): array;
}
