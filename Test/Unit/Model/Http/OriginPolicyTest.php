<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Http;

use Hryvinskyi\McpServer\Model\Http\OriginPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The origins the MCP endpoint serves: those whose host is the host of a store base URL
 */
class OriginPolicyTest extends TestCase
{
    /**
     * Base URLs by store id and config path; the admin store (0) and two store views
     */
    private const BASE_URLS = [
        0 => ['web/unsecure/base_url' => 'http://shop.example/', 'web/secure/base_url' => 'https://shop.example/'],
        1 => ['web/unsecure/base_url' => 'http://shop.example/', 'web/secure/base_link_url' => 'https://shop.example/'],
        2 => [
            'web/unsecure/base_url' => 'http://Second-Store.example:8080/',
            'web/secure/base_url' => '{{unsecure_base_url}}',
            'web/secure/base_link_url' => 'https://user:secret@Admin-Host.example/index.php/',
            'web/unsecure/base_link_url' => 'http://[::1]:8080/',
        ],
    ];

    public function testARequestWithoutAnOriginIsAllowed(): void
    {
        self::assertTrue($this->policy()->allows(''));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function servedOrigins(): array
    {
        return [
            'the default host over http' => ['http://shop.example'],
            'the default host over https' => ['https://shop.example'],
            'another store view, in another case' => ['http://second-store.EXAMPLE:8080'],
            'another store view on another port' => ['https://second-store.example'],
            'a served host with user info in a base URL' => ['https://admin-host.example'],
            'an IPv6 host' => ['http://[::1]:8080'],
        ];
    }

    #[DataProvider('servedOrigins')]
    public function testAnOriginWhoseHostAStoreServesIsAllowed(string $origin): void
    {
        self::assertTrue($this->policy()->allows($origin));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignOrigins(): array
    {
        return [
            'a foreign host' => ['http://rebound.example'],
            'a host that only ends with a served one' => ['http://evil-shop.example'],
            'a served host as a subdomain of a foreign one' => ['http://shop.example.rebound.example'],
            'the opaque origin of a sandboxed page' => ['null'],
            'not a URL' => ['shop.example'],
            'a served host after user info' => ['http://shop.example@rebound.example'],
            'a URL without a host' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('foreignOrigins')]
    public function testAnOriginWhoseHostNoStoreServesIsRefused(string $origin): void
    {
        self::assertFalse($this->policy()->allows($origin));
    }

    public function testTheBaseUrlsAreReadOncePerRequestAndAgainAfterAReset(): void
    {
        $storeManager = $this->storeManager();
        $storeManager->expects(self::exactly(2))->method('getStores')->with(true);
        $policy = new OriginPolicy($storeManager, $this->scopeConfig());

        $policy->allows('http://shop.example');
        $policy->allows('http://rebound.example');
        $policy->_resetState();
        $policy->allows('http://shop.example');
    }

    /**
     * @return OriginPolicy
     */
    private function policy(): OriginPolicy
    {
        return new OriginPolicy($this->storeManager(), $this->scopeConfig());
    }

    /**
     * A store manager that knows the stores of BASE_URLS
     *
     * @return StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function storeManager(): StoreManagerInterface
    {
        $stores = [];
        foreach (array_keys(self::BASE_URLS) as $storeId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return $storeManager;
    }

    /**
     * Configuration holding BASE_URLS at store scope
     *
     * @return ScopeConfigInterface
     */
    private function scopeConfig(): ScopeConfigInterface
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, mixed $storeId): ?string {
                self::assertSame(ScopeInterface::SCOPE_STORE, $scope);
                self::assertIsInt($storeId);

                return self::BASE_URLS[$storeId][$path] ?? null;
            }
        );

        return $scopeConfig;
    }
}
