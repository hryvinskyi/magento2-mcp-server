<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Model\Http\McpRequestProcessor;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Webapi\Controller\PathProcessor;

/**
 * The client connection of this store: the server name `magento` and the endpoint of the MCP REST route
 *
 * - The REST path is composed from the facts that already have an owner: Magento's REST prefix, the Web API's `all`
 *   store code alias the MCP route is served under, and the route's own path.
 * - The endpoint is the default store view's secure base URL of type web, which carries no store code and no
 *   `index.php`, joined to that path by exactly one slash. When the default store view's base URL cannot be read,
 *   the endpoint is the path alone, rooted at `/`.
 */
class ClientConnection implements ClientConnectionInterface
{
    public const SERVER_NAME = 'magento';
    private const REST_PATH = 'rest/' . PathProcessor::ALL_STORE_CODE . McpRequestProcessor::PATH;

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function serverName(): string
    {
        return self::SERVER_NAME;
    }

    /**
     * @inheritDoc
     */
    public function endpointUrl(): string
    {
        $store = $this->storeManager->getDefaultStoreView();
        $base = $store instanceof Store ? (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true) : '';

        return rtrim($base, '/') . '/' . self::REST_PATH;
    }
}
