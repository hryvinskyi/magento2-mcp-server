<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Source\GraphQl\Client;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\StoredConfigInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The derived GraphQL endpoint against the settings as Magento stores them: a base URL saved as a placeholder, which
 * Magento would fill in from each request's host, is refused, read from the database past every cache and processor
 */
#[DbIsolation(true)]
class EndpointUrlTest extends TestCase
{
    use BlocksRealGraphQlClient;

    public function testTheStoredValueIsReadAsSavedWithItsPlaceholderAndPastTheConfigCache(): void
    {
        $store = $this->defaultStoreView();
        $this->save(Store::XML_PATH_SECURE_BASE_URL, '{{base_url}}', $store);

        self::assertSame(
            '{{base_url}}',
            $this->storedConfig()->storeViewValue(Store::XML_PATH_SECURE_BASE_URL, $store)
        );
        $resolved = $this->scopeConfig()->getValue(
            Store::XML_PATH_SECURE_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $store->getCode()
        );
        self::assertIsString($resolved);
        self::assertStringNotContainsString(
            '{{',
            $resolved,
            'The resolved value never shows the placeholder, which is why the stored one is read.'
        );
    }

    public function testABaseUrlNothingStoresIsTheShippedPlaceholderAndGivesNoEndpoint(): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->delete(
            $resource->getTableName('core_config_data'),
            ['path IN (?)' => [Store::XML_PATH_SECURE_BASE_URL, Store::XML_PATH_UNSECURE_BASE_URL]]
        );

        $this->expectException(GraphQlTransportException::class);
        $this->expectExceptionMessage('set the GraphQL Endpoint URL setting');
        $this->endpoint()->url();
    }

    public function testAStoreViewWhoseSecureBaseUrlIsSavedFixedGivesTheEndpoint(): void
    {
        $this->save(Store::XML_PATH_SECURE_BASE_URL, 'https://fixed.example/', $this->defaultStoreView());

        $url = $this->endpoint()->url();

        self::assertStringStartsWith('http', $url);
        self::assertStringEndsWith('/graphql', $url);
    }

    public function testAStoreViewWhoseSecureBaseUrlIsSavedAsAPlaceholderGivesNoEndpoint(): void
    {
        $this->save(Store::XML_PATH_SECURE_BASE_URL, '{{base_url}}', $this->defaultStoreView());

        $this->expectException(GraphQlTransportException::class);
        $this->expectExceptionMessage('set the GraphQL Endpoint URL setting');
        $this->endpoint()->url();
    }

    public function testASecureBaseUrlNamingAnUnsecureOneSavedAsAPlaceholderGivesNoEndpoint(): void
    {
        $store = $this->defaultStoreView();
        $this->save(Store::XML_PATH_SECURE_BASE_URL, '{{unsecure_base_url}}', $store);
        $this->save(Store::XML_PATH_UNSECURE_BASE_URL, '{{base_url}}', $store);

        $this->expectException(GraphQlTransportException::class);
        $this->expectExceptionMessage('set the GraphQL Endpoint URL setting');
        $this->endpoint()->url();
    }

    public function testASecureBaseUrlNamingAFixedUnsecureOneGivesTheEndpoint(): void
    {
        $store = $this->defaultStoreView();
        $this->save(Store::XML_PATH_SECURE_BASE_URL, '{{unsecure_base_url}}', $store);
        $this->save(Store::XML_PATH_UNSECURE_BASE_URL, 'https://fixed.example/', $store);

        self::assertStringEndsWith('/graphql', $this->endpoint()->url());
    }

    /**
     * Save a setting for the store view in the database, as the admin would
     *
     * @param string $path
     * @param string $value
     * @param StoreInterface $store
     * @return void
     */
    private function save(string $path, string $value, StoreInterface $store): void
    {
        $writer = Bootstrap::getObjectManager()->get(WriterInterface::class);
        self::assertInstanceOf(WriterInterface::class, $writer);
        $writer->save($path, $value, ScopeInterface::SCOPE_STORES, (int) $store->getId());
    }

    /**
     * @return StoreInterface
     */
    private function defaultStoreView(): StoreInterface
    {
        $storeManager = Bootstrap::getObjectManager()->get(StoreManagerInterface::class);
        self::assertInstanceOf(StoreManagerInterface::class, $storeManager);
        $store = $storeManager->getDefaultStoreView();
        self::assertNotNull($store);

        return $store;
    }

    /**
     * @return EndpointInterface
     */
    private function endpoint(): EndpointInterface
    {
        $endpoint = Bootstrap::getObjectManager()->create(EndpointInterface::class);
        self::assertInstanceOf(EndpointInterface::class, $endpoint);

        return $endpoint;
    }

    /**
     * @return StoredConfigInterface
     */
    private function storedConfig(): StoredConfigInterface
    {
        $storedConfig = Bootstrap::getObjectManager()->get(StoredConfigInterface::class);
        self::assertInstanceOf(StoredConfigInterface::class, $storedConfig);

        return $storedConfig;
    }

    /**
     * @return ScopeConfigInterface
     */
    private function scopeConfig(): ScopeConfigInterface
    {
        $scopeConfig = Bootstrap::getObjectManager()->get(ScopeConfigInterface::class);
        self::assertInstanceOf(ScopeConfigInterface::class, $scopeConfig);

        return $scopeConfig;
    }
}
