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
 * Names the GraphQL endpoint in the settings for every test of a class that reads the GraphQL schema through a fake
 * client; the setting is put back after each test
 *
 * The test installation stores no base URL, so its base URL is the shipped placeholder that Magento fills in from
 * each request, and the server derives no endpoint from it. A merchant in that situation sets the endpoint URL; so
 * do these tests. No request is ever sent to it: the tests hand the source a fake client.
 *
 * The integration framework reads its config fixture attribute only on test methods, never on the class, so the
 * setting is changed here, through the same mutable scope config that fixture writes to.
 */
trait NamesGraphQlEndpoint
{
    /**
     * The setting's value before the test
     *
     * @var mixed
     */
    private mixed $endpointUrlBefore = null;

    /**
     * @return void
     */
    #[Before]
    protected function nameGraphQlEndpoint(): void
    {
        $config = $this->endpointScopeConfig();
        $this->endpointUrlBefore = $config->getValue(GraphQlConfig::XML_PATH_ENDPOINT_URL);
        $config->setValue(GraphQlConfig::XML_PATH_ENDPOINT_URL, 'http://localhost/graphql');
    }

    /**
     * @return void
     */
    #[After]
    protected function restoreGraphQlEndpoint(): void
    {
        $this->endpointScopeConfig()->setValue(GraphQlConfig::XML_PATH_ENDPOINT_URL, $this->endpointUrlBefore);
    }

    /**
     * @return MutableScopeConfigInterface
     */
    private function endpointScopeConfig(): MutableScopeConfigInterface
    {
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        self::assertInstanceOf(MutableScopeConfigInterface::class, $config);

        return $config;
    }
}
