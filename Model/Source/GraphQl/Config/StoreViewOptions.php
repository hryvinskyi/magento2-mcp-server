<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Config;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store views GraphQL abilities may run in, by store code, after an empty choice meaning the default store view
 *
 * Codes rather than ids are stored, so the setting names the store view the same way the `Store` header does.
 */
class StoreViewOptions implements OptionSourceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return list<array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Default Store View')]];
        foreach ($this->storeManager->getStores() as $store) {
            $code = (string) $store->getCode();
            $options[] = ['value' => $code, 'label' => sprintf('%s (%s)', (string) $store->getName(), $code)];
        }

        return $options;
    }
}
