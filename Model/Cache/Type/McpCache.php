<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Cache type "MCP Server": what the module caches, cleaned on its own from Cache Management
 */
class McpCache extends TagScope
{
    /**
     * Cache type code, unique among all cache types
     */
    public const TYPE_IDENTIFIER = 'hryvinskyi_mcp';

    /**
     * Tag every entry of the type carries, which scopes cleaning to the type
     */
    public const CACHE_TAG = 'HRYVINSKYI_MCP';

    /**
     * Tag of every entry derived from Magento's Web API metadata: the input schemas, and the memory of a metadata
     * build that failed
     */
    public const SCHEMA_TAG = 'HRYVINSKYI_MCP_SCHEMA';

    /**
     * @param FrontendPool $cacheFrontendPool
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
