<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Http;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Whether a request's `Origin` header names a host this installation serves
 *
 * A browser sends `Origin` with every cross-site request. A page on a foreign host that rebinds its DNS name to this
 * server still sends its own host as the origin, so refusing every origin whose host is not the host of a store base
 * URL (secure or not, base or link URL, of any store, the admin store included) stops it. A request without an
 * `Origin` header comes from a client that is not a browser page and is allowed. An origin with no host, such as the
 * opaque `null` a sandboxed page sends, is refused. Hosts are compared without regard to case. The hosts are read once
 * per request.
 */
class OriginPolicy implements ResetAfterRequestInterface
{
    private const BASE_URL_PATHS = [
        'web/unsecure/base_url',
        'web/secure/base_url',
        'web/unsecure/base_link_url',
        'web/secure/base_link_url',
    ];

    /**
     * @var array<string, true>|null
     */
    private ?array $hosts = null;

    /**
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlHost $urlHost
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlHost $urlHost
    ) {
    }

    /**
     * Whether a request with this `Origin` header value may reach the server
     *
     * @param string $origin The header's value, or '' when the request has none
     * @return bool
     */
    public function allows(string $origin): bool
    {
        if ($origin === '') {
            return true;
        }

        $host = $this->urlHost->of($origin);

        return $host !== null && isset($this->servedHosts()[$host]);
    }

    /**
     * Forget the hosts, so the next request reads the base URLs again
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->hosts = null;
    }

    /**
     * The lowercase hosts of every store's base URLs
     *
     * @return array<string, true>
     */
    private function servedHosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }

        $hosts = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            foreach (self::BASE_URL_PATHS as $path) {
                $url = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store->getId());
                $host = is_string($url) ? $this->urlHost->of($url) : null;
                if ($host !== null) {
                    $hosts[$host] = true;
                }
            }
        }

        return $this->hosts = $hosts;
    }
}
