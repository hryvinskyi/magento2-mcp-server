<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfig;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Switches the GraphQL abilities off for every test of a class that builds the ability catalog but is not about
 * GraphQL, so the catalog never reads the GraphQL schema; the setting is put back after each test
 *
 * The integration framework reads its config fixture attribute only on test methods, never on the class, so the
 * setting is changed here, through the same mutable scope config that fixture writes to.
 */
trait SwitchesGraphQlSourceOff
{
    /**
     * The setting's value before the test
     *
     * @var mixed
     */
    private mixed $graphQlEnabledBefore = null;

    /**
     * @return void
     */
    #[Before]
    protected function switchGraphQlSourceOff(): void
    {
        $config = $this->mutableScopeConfig();
        $this->graphQlEnabledBefore = $config->getValue(GraphQlConfig::XML_PATH_ENABLED);
        $config->setValue(GraphQlConfig::XML_PATH_ENABLED, '0');
    }

    /**
     * @return void
     */
    #[After]
    protected function restoreGraphQlSource(): void
    {
        $this->mutableScopeConfig()->setValue(GraphQlConfig::XML_PATH_ENABLED, $this->graphQlEnabledBefore);
    }

    /**
     * @return MutableScopeConfigInterface
     */
    private function mutableScopeConfig(): MutableScopeConfigInterface
    {
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        self::assertInstanceOf(MutableScopeConfigInterface::class, $config);

        return $config;
    }
}
