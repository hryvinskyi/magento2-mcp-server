<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Settings exactly as they are stored, before Magento resolves the placeholders in them
 *
 * Magento resolves a `{{base_url}}` placeholder in a base URL from the host of the request being served. Only the
 * stored value tells whether a base URL is fixed or follows each request.
 */
interface StoredConfigInterface
{
    /**
     * The value stored for this path for the store view, with its placeholders unresolved
     *
     * The store view's own scope is tried first, then its website's, then the default scope. '' when none of these
     * scopes stores one.
     *
     * @param string $path A configuration path such as `web/secure/base_url`
     * @param StoreInterface $store
     * @return string
     * @throws LocalizedException When the store view's website cannot be found
     */
    public function storeViewValue(string $path, StoreInterface $store): string;
}
