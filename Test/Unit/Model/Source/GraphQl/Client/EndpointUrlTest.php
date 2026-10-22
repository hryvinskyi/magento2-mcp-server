<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Client;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointUrl;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointUrlValidator;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\StoredConfigInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfigInterface;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeStoredConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint from the settings: the URL setting or the store view's web base URL, and the store view to run in;
 * a derived URL that is not a GraphQL URL, or that Magento fills in from each request's host, is refused
 */
class EndpointUrlTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * Base URLs asked for: type and whether secure
     *
     * @var list<array{mixed, mixed}>
     */
    private array $baseUrlRequests = [];

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->baseUrlRequests = [];
    }

    public function testWithoutSettingsItIsTheDefaultStoreViewsSecureWebBaseUrlAndCode(): void
    {
        $endpoint = $this->endpoint('', '', $this->stores());

        self::assertSame('https://shop.test/graphql', $endpoint->url());
        self::assertSame('default', $endpoint->storeCode());
        self::assertSame([[UrlInterface::URL_TYPE_WEB, true]], $this->baseUrlRequests);
        self::assertSame([], $this->logger->records);
    }

    public function testTheChosenStoreViewGivesItsCodeAndBaseUrl(): void
    {
        $endpoint = $this->endpoint('', 'trade', $this->stores());

        self::assertSame('trade', $endpoint->storeCode());
        self::assertSame('https://trade.test/graphql', $endpoint->url());
        self::assertSame([], $this->logger->records);
    }

    public function testAConfiguredUrlWinsAndKeepsTheChosenStoreView(): void
    {
        $endpoint = $this->endpoint('http://web:8080/graphql', 'trade', $this->stores());

        self::assertSame('http://web:8080/graphql', $endpoint->url());
        self::assertSame('trade', $endpoint->storeCode());
        self::assertSame([], $this->baseUrlRequests);
    }

    public function testAConfiguredUrlThatIsNotAGraphqlUrlIsRefused(): void
    {
        $endpoint = $this->endpoint('ftp://shop.test/graphql', '', $this->stores());

        $this->expectException(GraphQlTransportException::class);
        $endpoint->url();
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function storedPlaceholders(): array
    {
        return [
            'the secure base URL is the request\'s base URL' => [
                ['web/secure/base_url' => '{{base_url}}', 'web/unsecure/base_url' => 'http://shop.test/'],
            ],
            'it names the unsecure one, which is the request\'s base URL' => [
                ['web/secure/base_url' => '{{unsecure_base_url}}', 'web/unsecure/base_url' => '{{base_url}}'],
            ],
            'it names the unsecure one, which nothing stores' => [
                ['web/secure/base_url' => '{{unsecure_base_url}}'],
            ],
            'nothing stores it, so the shipped placeholder applies' => [[]],
            'a placeholder inside a longer value' => [['web/secure/base_url' => 'https://{{host}}/']],
        ];
    }

    /**
     * @param array<string, string> $stored
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storedPlaceholders')]
    public function testADerivedUrlIsRefusedWhenTheStoredBaseUrlIsFilledInFromEachRequest(array $stored): void
    {
        $endpoint = $this->endpoint('', '', $this->stores(), new FakeStoredConfig(['default' => $stored]));

        try {
            $endpoint->url();
            self::fail('A base URL that follows the request was used.');
        } catch (GraphQlTransportException $exception) {
            self::assertStringContainsString('"default"', $exception->getMessage());
            self::assertStringContainsString('set the GraphQL Endpoint URL setting', $exception->getMessage());
        }
        self::assertSame([], $this->baseUrlRequests, 'The resolved base URL is never read.');
    }

    public function testASecureBaseUrlThatNamesAFixedUnsecureOneIsUsed(): void
    {
        $stored = ['web/secure/base_url' => '{{unsecure_base_url}}', 'web/unsecure/base_url' => 'https://shop.test/'];

        $endpoint = $this->endpoint('', '', $this->stores(), new FakeStoredConfig(['default' => $stored]));

        self::assertSame('https://shop.test/graphql', $endpoint->url());
    }

    public function testAPlaceholderStoredForAnotherStoreViewDoesNotMatter(): void
    {
        $stored = new FakeStoredConfig([
            'default' => ['web/secure/base_url' => '{{base_url}}'],
            'trade' => ['web/secure/base_url' => 'https://trade.test/'],
        ]);

        self::assertSame('https://trade.test/graphql', $this->endpoint('', 'trade', $this->stores(), $stored)->url());
    }

    public function testADerivedUrlThatIsNotAGraphqlUrlIsRefused(): void
    {
        $endpoint = $this->endpoint('', 'odd', $this->stores());

        try {
            $endpoint->url();
            self::fail('A derived URL that is not an absolute http(s) URL was used.');
        } catch (GraphQlTransportException $exception) {
            self::assertStringContainsString('"odd"', $exception->getMessage());
            self::assertStringContainsString('set the GraphQL Endpoint URL setting', $exception->getMessage());
        }
    }

    /**
     * @param string $code
     */
    #[\PHPUnit\Framework\Attributes\TestWith(['unknown'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['closed'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['admin'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['2'])]
    public function testAStoreCodeThatNamesNoActiveStoreViewFallsBackToTheDefaultWithAWarning(string $code): void
    {
        $endpoint = $this->endpoint('', $code, $this->stores());

        self::assertSame('default', $endpoint->storeCode());
        self::assertSame('https://shop.test/graphql', $endpoint->url());
        $warnings = $this->logger->messages('warning');
        self::assertCount(2, $warnings);
        self::assertStringContainsString(sprintf('"%s"', $code), $warnings[0]);
    }

    public function testWithoutADefaultStoreViewNoEndpointCanBeNamed(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn(null);
        $endpoint = $this->endpoint('', '', $storeManager);

        $this->expectException(GraphQlTransportException::class);
        $endpoint->storeCode();
    }

    /**
     * @param string $url
     * @param string $storeCode
     * @param StoreManagerInterface $storeManager
     * @param StoredConfigInterface|null $stored The stored base URLs; each store view's fixed URL when null
     * @return EndpointUrl
     */
    private function endpoint(
        string $url,
        string $storeCode,
        StoreManagerInterface $storeManager,
        ?StoredConfigInterface $stored = null
    ): EndpointUrl {
        $config = $this->createStub(GraphQlConfigInterface::class);
        $config->method('getEndpointUrl')->willReturn($url);
        $config->method('getStoreCode')->willReturn($storeCode);
        $fixed = static fn (string $baseUrl): array => [
            'web/secure/base_url' => $baseUrl,
            'web/unsecure/base_url' => $baseUrl,
        ];

        return new EndpointUrl(
            $config,
            $storeManager,
            new EndpointUrlValidator(),
            $stored ?? new FakeStoredConfig([
                'default' => $fixed('https://shop.test/'),
                'trade' => $fixed('https://trade.test/'),
                'odd' => $fixed('https://odd.test/'),
            ]),
            $this->logger
        );
    }

    /**
     * A store manager knowing the admin store, three active store views (`default` id 1, `trade` id 2, and `odd`
     * id 4, whose resolved base URL is no http(s) URL) and an inactive one (`closed`)
     *
     * @return StoreManagerInterface
     */
    private function stores(): StoreManagerInterface
    {
        $stores = [
            'admin' => $this->store(0, 'admin', true, 'https://admin.test/'),
            'default' => $this->store(1, 'default', true, 'https://shop.test/'),
            'trade' => $this->store(2, 'trade', true, 'https://trade.test/'),
            'closed' => $this->store(3, 'closed', false, 'https://closed.test/'),
            'odd' => $this->store(4, 'odd', true, 'javascript:alert(1)//'),
        ];
        $byId = [
            '0' => $stores['admin'],
            '1' => $stores['default'],
            '2' => $stores['trade'],
            '3' => $stores['closed'],
            '4' => $stores['odd'],
        ];

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($stores['default']);
        $storeManager->method('getStore')->willReturnCallback(
            static function (mixed $code) use ($stores, $byId): Store {
                $key = is_scalar($code) ? (string) $code : '';
                $store = $stores[$key] ?? $byId[$key] ?? null;
                if ($store === null) {
                    throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
                }

                return $store;
            }
        );

        return $storeManager;
    }

    /**
     * @param int $id
     * @param string $code
     * @param bool $active
     * @param string $baseUrl
     * @return Store
     */
    private function store(int $id, string $code, bool $active, string $baseUrl): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getIsActive')->willReturn($active ? 1 : 0);
        $store->method('getBaseUrl')->willReturnCallback(
            function (mixed $type, mixed $secure) use ($baseUrl): string {
                $this->baseUrlRequests[] = [$type, $secure];

                return $baseUrl;
            }
        );

        return $store;
    }
}
