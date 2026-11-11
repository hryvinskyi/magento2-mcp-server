<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Store;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The time zones of the store views, as the locale setting of each store view gives them
 */
class StoreTimeZones implements StoreTimeZonesInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @inheritDoc
     */
    public function zones(): array
    {
        $zones = [];
        foreach ($this->storeManager->getStores() as $store) {
            $code = (string) $store->getCode();
            $zones[$code] = $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, $code);
        }

        return $zones;
    }
}
