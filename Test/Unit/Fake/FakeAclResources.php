<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\Acl\AclResource\ProviderInterface;

/**
 * A small ACL tree shaped like Magento's: the admin root with Catalog, Sales and Stores, and Stores holding settings
 * and inventory, next to a tree that is not the admin's
 */
class FakeAclResources implements ProviderInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getAclResources()
    {
        return [
            ['id' => 'Magento_Backend::admin', 'title' => 'Magento Admin', 'children' => [
                ['id' => 'Magento_Catalog::catalog', 'title' => 'Catalog', 'children' => [
                    ['id' => 'Magento_Catalog::catalog_inventory', 'title' => 'Inventory', 'children' => [
                        ['id' => 'Magento_Catalog::products', 'title' => 'Products', 'children' => [
                            ['id' => 'Magento_Catalog::edit', 'title' => 'Edit Product Design', 'children' => []],
                        ]],
                    ]],
                ]],
                ['id' => 'Magento_Sales::sales', 'title' => 'Sales', 'children' => [
                    ['id' => 'Magento_Sales::actions_view', 'title' => 'View', 'children' => []],
                ]],
                ['id' => 'Magento_Backend::stores', 'title' => 'Stores', 'children' => [
                    ['id' => 'Magento_Backend::stores_settings', 'title' => 'Settings', 'children' => [
                        ['id' => 'Magento_Config::config', 'title' => 'Configuration', 'children' => []],
                    ]],
                    ['id' => 'Magento_InventoryApi::inventory', 'title' => 'Inventory', 'children' => [
                        ['id' => 'Magento_InventoryApi::source', 'title' => 'Sources', 'children' => []],
                    ]],
                ]],
            ]],
            ['id' => 'Magento_Webapi::other_tree', 'title' => 'Another tree', 'children' => []],
        ];
    }
}
