<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Client;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\StoredSystemConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedConfigSource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Stored settings as the deployment configuration and the database hold them: the store view's scope, then its
 * website's, then the default scope, the deployment configuration first in each, placeholders left as they are
 */
class StoredSystemConfigTest extends TestCase
{
    private const PATH = 'web/secure/base_url';

    public function testTheStoreViewsOwnValueWinsOverItsWebsitesAndTheDefault(): void
    {
        $config = $this->config([], [
            'default' => ['web' => ['secure' => ['base_url' => 'https://default.test/']]],
            'websites' => ['base' => ['web' => ['secure' => ['base_url' => 'https://website.test/']]]],
            'stores' => ['trade' => ['web' => ['secure' => ['base_url' => '{{base_url}}']]]],
        ]);

        self::assertSame('{{base_url}}', $config->storeViewValue(self::PATH, $this->store('trade')));
        self::assertSame('https://website.test/', $config->storeViewValue(self::PATH, $this->store('default')));
    }

    public function testTheDefaultScopeAnswersWhenNeitherTheStoreViewNorItsWebsiteStoresOne(): void
    {
        $config = $this->config([], [
            'default' => ['web' => ['secure' => ['base_url' => '{{unsecure_base_url}}']]],
        ]);

        self::assertSame('{{unsecure_base_url}}', $config->storeViewValue(self::PATH, $this->store('default')));
    }

    public function testTheDeploymentConfigurationWinsOverTheDatabaseInTheSameScope(): void
    {
        $config = $this->config(
            ['default' => ['web' => ['secure' => ['base_url' => 'https://deployed.test/']]]],
            ['default' => ['web' => ['secure' => ['base_url' => 'https://saved.test/']]]]
        );

        self::assertSame('https://deployed.test/', $config->storeViewValue(self::PATH, $this->store('default')));
    }

    public function testANarrowerScopeInTheDatabaseWinsOverAWiderOneInTheDeploymentConfiguration(): void
    {
        $config = $this->config(
            ['default' => ['web' => ['secure' => ['base_url' => 'https://deployed.test/']]]],
            ['stores' => ['default' => ['web' => ['secure' => ['base_url' => 'https://saved.test/']]]]]
        );

        self::assertSame('https://saved.test/', $config->storeViewValue(self::PATH, $this->store('default')));
    }

    public function testNothingStoredIsAnEmptyValue(): void
    {
        $config = $this->config([], ['default' => ['web' => ['secure' => ['base_link_url' => 'https://x.test/']]]]);

        self::assertSame('', $config->storeViewValue(self::PATH, $this->store('default')));
    }

    public function testAValueThatIsNotScalarCountsAsNotStored(): void
    {
        $config = $this->config([], [
            'stores' => ['default' => ['web' => ['secure' => ['base_url' => ['nested' => 'x']]]]],
            'default' => ['web' => ['secure' => ['base_url' => 'https://default.test/']]],
        ]);

        self::assertSame('https://default.test/', $config->storeViewValue(self::PATH, $this->store('default')));
    }

    public function testBothSourcesAreReadAgainOnEveryCall(): void
    {
        $deployment = new FixedConfigSource([]);
        $database = new FixedConfigSource([]);
        $config = new StoredSystemConfig($deployment, $database, $this->storeManager());

        $config->storeViewValue(self::PATH, $this->store('default'));
        $config->storeViewValue(self::PATH, $this->store('default'));

        self::assertSame([2, 2], [$deployment->reads, $database->reads]);
    }

    public function testAStoreViewWhoseWebsiteIsUnknownCannotBeRead(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willThrowException(
            new NoSuchEntityException(__('The website with code "x" that was requested wasn\'t found.'))
        );
        $config = new StoredSystemConfig(new FixedConfigSource([]), new FixedConfigSource([]), $storeManager);

        $this->expectException(NoSuchEntityException::class);
        $config->storeViewValue(self::PATH, $this->store('default'));
    }

    /**
     * @param array<string, mixed> $deployment
     * @param array<string, mixed> $database
     * @return StoredSystemConfig
     */
    private function config(array $deployment, array $database): StoredSystemConfig
    {
        return new StoredSystemConfig(
            new FixedConfigSource($deployment),
            new FixedConfigSource($database),
            $this->storeManager()
        );
    }

    /**
     * A store manager whose every store view belongs to the website `base`
     *
     * @return StoreManagerInterface
     */
    private function storeManager(): StoreManagerInterface
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        return $storeManager;
    }

    /**
     * @param string $code
     * @return StoreInterface
     */
    private function store(string $code): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn($code);
        $store->method('getWebsiteId')->willReturn(1);

        return $store;
    }
}
