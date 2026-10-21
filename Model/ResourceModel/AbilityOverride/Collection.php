<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\ResourceModel\AbilityOverride;

use Hryvinskyi\McpServer\Model\AbilityOverride;
use Hryvinskyi\McpServer\Model\ResourceModel\AbilityOverride as AbilityOverrideResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * The stored per-ability overrides
 */
class Collection extends AbstractCollection
{
    /**
     * Bind the collection to its model and resource
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AbilityOverride::class, AbilityOverrideResource::class);
    }
}
