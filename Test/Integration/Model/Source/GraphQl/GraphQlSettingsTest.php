<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointUrl;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\HttpGraphQlClient;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Config\EndpointUrlValue;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Config\StoreViewOptions;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfig;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfigInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The GraphQL settings, the endpoint they name, and the client, as the container builds them; nothing here sends a
 * request
 */
class GraphQlSettingsTest extends TestCase
{
    use BlocksRealGraphQlClient;

    public function testAfterInstallGraphqlAbilitiesAreOnWithoutMutationsAtTheDefaultEndpoint(): void
    {
        $config = self::objectManager()->create(GraphQlConfigInterface::class);

        self::assertInstanceOf(GraphQlConfig::class, $config);
        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isMutationExposed());
        self::assertSame('', $config->getEndpointUrl());
        self::assertSame('', $config->getStoreCode());
    }

    #[ConfigFixture(GraphQlConfig::XML_PATH_ENABLED, '0')]
    #[ConfigFixture(GraphQlConfig::XML_PATH_EXPOSE_MUTATIONS, '1')]
    #[ConfigFixture(GraphQlConfig::XML_PATH_ENDPOINT_URL, 'http://web:8080/graphql')]
    #[ConfigFixture(GraphQlConfig::XML_PATH_STORE_CODE, 'default')]
    public function testReadsTheMerchantsChoice(): void
    {
        $config = self::objectManager()->create(GraphQlConfigInterface::class);
        self::assertInstanceOf(GraphQlConfigInterface::class, $config);

        self::assertFalse($config->isEnabled());
        self::assertTrue($config->isMutationExposed());
        self::assertSame('http://web:8080/graphql', $config->getEndpointUrl());
        self::assertSame('default', $config->getStoreCode());
    }

    #[DbIsolation(true)]
    public function testTheDefaultEndpointIsTheDefaultStoreViewsSecureWebBaseUrl(): void
    {
        $default = $this->defaultStoreView();
        $writer = self::objectManager()->get(WriterInterface::class);
        self::assertInstanceOf(WriterInterface::class, $writer);
        $writer->save(Store::XML_PATH_SECURE_BASE_URL, (string) $default->getBaseUrl(UrlInterface::URL_TYPE_WEB, true));
        $endpoint = self::objectManager()->create(EndpointInterface::class);

        self::assertInstanceOf(EndpointUrl::class, $endpoint);
        self::assertSame($default->getBaseUrl(UrlInterface::URL_TYPE_WEB, true) . 'graphql', $endpoint->url());
        self::assertSame($default->getCode(), $endpoint->storeCode());
    }

    #[ConfigFixture(GraphQlConfig::XML_PATH_ENDPOINT_URL, 'http://web:8080/graphql')]
    #[ConfigFixture(GraphQlConfig::XML_PATH_STORE_CODE, 'default')]
    public function testTheEndpointFollowsTheSettings(): void
    {
        $endpoint = self::objectManager()->create(EndpointInterface::class);
        self::assertInstanceOf(EndpointInterface::class, $endpoint);

        self::assertSame('http://web:8080/graphql', $endpoint->url());
        self::assertSame('default', $endpoint->storeCode());
    }

    #[ConfigFixture(GraphQlConfig::XML_PATH_STORE_CODE, 'no_such_store_view')]
    public function testAnUnknownStoreViewFallsBackToTheDefaultOne(): void
    {
        $endpoint = self::objectManager()->create(EndpointInterface::class);
        self::assertInstanceOf(EndpointInterface::class, $endpoint);

        self::assertSame($this->defaultStoreView()->getCode(), $endpoint->storeCode());
    }

    public function testTheClientIsTheHttpAdapter(): void
    {
        self::assertInstanceOf(
            HttpGraphQlClient::class,
            self::objectManager()->create(GraphQlClientInterface::class)
        );
    }

    /**
     * @param string $url
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['ftp://shop.test/graphql'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['https://shop.test/rest/V1/products'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['https://user:secret@shop.test/graphql'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['/graphql'])]
    public function testTheEndpointUrlSettingRefusesAnythingButAnAbsoluteGraphqlUrl(string $url): void
    {
        $value = self::objectManager()->create(EndpointUrlValue::class);
        self::assertInstanceOf(EndpointUrlValue::class, $value);
        $value->setValue($url);

        $this->expectException(LocalizedException::class);
        $value->beforeSave();
    }

    /**
     * @param string $entered
     * @param string $saved
     */
    #[\PHPUnit\Framework\Attributes\TestWith([' https://shop.test/graphql ', 'https://shop.test/graphql'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['', ''])]
    public function testTheEndpointUrlSettingKeepsAGraphqlUrlOrNothingTrimmed(string $entered, string $saved): void
    {
        $value = self::objectManager()->create(EndpointUrlValue::class);
        self::assertInstanceOf(EndpointUrlValue::class, $value);
        $value->setValue($entered);

        $value->beforeSave();

        self::assertSame($saved, $value->getValue());
    }

    public function testTheStoreViewChoicesAreTheDefaultThenEveryStoreViewByCode(): void
    {
        $options = self::objectManager()->create(StoreViewOptions::class);
        self::assertInstanceOf(StoreViewOptions::class, $options);

        $values = array_column($options->toOptionArray(), 'value');

        self::assertSame('', $values[0]);
        self::assertContains($this->defaultStoreView()->getCode(), $values);
        self::assertNotContains('admin', $values);
    }

    /**
     * @return Store
     */
    private function defaultStoreView(): Store
    {
        $storeManager = self::objectManager()->get(StoreManagerInterface::class);
        self::assertInstanceOf(StoreManagerInterface::class, $storeManager);
        $store = $storeManager->getDefaultStoreView();
        self::assertInstanceOf(Store::class, $store);

        return $store;
    }

    /**
     * @return ObjectManager
     */
    private static function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
