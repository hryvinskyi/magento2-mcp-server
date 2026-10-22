<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Model\Config;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The settings as the container reads them
 */
class ConfigTest extends TestCase
{
    use BlocksRealGraphQlClient;

    public function testAfterInstallTheServerIsOffReadOnlyAndHidesAnonymousAbilities(): void
    {
        $config = $this->config();

        self::assertInstanceOf(Config::class, $config);
        self::assertFalse($config->isEnabled());
        self::assertTrue($config->isReadOnlyMode());
        self::assertFalse($config->isAnonymousExposed());
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testReadsTheMerchantsChoice(): void
    {
        $config = $this->config();

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isReadOnlyMode());
        self::assertTrue($config->isAnonymousExposed());
    }

    /**
     * @return ConfigInterface
     */
    private function config(): ConfigInterface
    {
        $config = Bootstrap::getObjectManager()->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $config);

        return $config;
    }
}
