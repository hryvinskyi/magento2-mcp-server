<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\StoredConfigInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Stored settings from a fixed map of store view code and path to the value as stored; '' for anything else
 */
class FakeStoredConfig implements StoredConfigInterface
{
    /**
     * @param array<string, array<string, string>> $values Value by path, by store view code
     */
    public function __construct(
        private readonly array $values
    ) {
    }

    /**
     * @inheritDoc
     */
    public function storeViewValue(string $path, StoreInterface $store): string
    {
        return $this->values[(string) $store->getCode()][$path] ?? '';
    }
}
