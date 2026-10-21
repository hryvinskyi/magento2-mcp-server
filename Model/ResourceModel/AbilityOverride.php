<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\ResourceModel;

use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Stores the merchant's per-ability overrides, one row per ability name
 *
 * The ability name is the primary key and is never generated, so saving an override of an ability without a row
 * inserts one and saving it again updates that row.
 */
class AbilityOverride extends AbstractDb
{
    public const TABLE = 'hryvinskyi_mcp_ability_override';

    /**
     * The ability name is the key; the table has no generated id
     *
     * @var bool
     */
    protected $_isPkAutoIncrement = false;

    /**
     * Bind the resource to its table
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, AbilityOverrideInterface::ABILITY);
    }
}
