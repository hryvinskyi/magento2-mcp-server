<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin;

use Hryvinskyi\McpServer\Model\Admin\ClientConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every client lists the server as `magento` and reaches it at the default store view's secure web base URL followed
 * by the MCP route's REST path, joined by one slash
 */
class ClientConnectionTest extends TestCase
{
    public function testTheServerNameIsMagento(): void
    {
        self::assertSame('magento', $this->connection(null)->serverName());
    }

    public function testTheEndpointIsTheDefaultStoreViewsSecureWebBaseUrlAndTheRestPath(): void
    {
        $store = $this->createMock(Store::class);
        $store->expects(self::once())
            ->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB, true)
            ->willReturn('https://shop.example/');

        self::assertSame('https://shop.example/rest/all/V1/mcp', $this->connection($store)->endpointUrl());
    }

    public function testABaseUrlWithoutATrailingSlashIsJoinedByOneSlash(): void
    {
        self::assertSame(
            'https://shop.example/rest/all/V1/mcp',
            $this->connection($this->store('https://shop.example'))->endpointUrl()
        );
    }

    public function testABaseUrlWithAPathKeepsItAndIsJoinedByOneSlash(): void
    {
        self::assertSame(
            'https://shop.example/eu/rest/all/V1/mcp',
            $this->connection($this->store('https://shop.example/eu/'))->endpointUrl()
        );
    }

    public function testADefaultStoreViewThatIsNotAStoreModelGivesTheRestPathAlone(): void
    {
        self::assertSame(
            '/rest/all/V1/mcp',
            $this->connection($this->createStub(StoreInterface::class))->endpointUrl()
        );
    }

    public function testNoDefaultStoreViewGivesTheRestPathAlone(): void
    {
        self::assertSame('/rest/all/V1/mcp', $this->connection(null)->endpointUrl());
    }

    /**
     * The connection of a store whose default store view is the given one
     *
     * @param StoreInterface|null $defaultStoreView
     * @return ClientConnection
     */
    private function connection(?StoreInterface $defaultStoreView): ClientConnection
    {
        /** @var StoreManagerInterface&MockObject $storeManager */
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStoreView);

        return new ClientConnection($storeManager);
    }

    /**
     * A store view whose base URL is the given one
     *
     * @param string $baseUrl
     * @return Store&MockObject
     */
    private function store(string $baseUrl): Store&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn($baseUrl);

        return $store;
    }
}
