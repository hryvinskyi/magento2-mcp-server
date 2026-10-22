<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * The GraphQL settings as read: flags at the default scope, text trimmed, a missing value empty
 */
class GraphQlConfigTest extends TestCase
{
    public function testReadsTheFlagsAndTrimmedTextAtTheDefaultScope(): void
    {
        $config = $this->config([
            GraphQlConfig::XML_PATH_ENABLED => '1',
            GraphQlConfig::XML_PATH_EXPOSE_MUTATIONS => '0',
            GraphQlConfig::XML_PATH_ENDPOINT_URL => "  http://web/graphql\n",
            GraphQlConfig::XML_PATH_STORE_CODE => ' trade ',
        ]);

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isMutationExposed());
        self::assertSame('http://web/graphql', $config->getEndpointUrl());
        self::assertSame('trade', $config->getStoreCode());
    }

    public function testMissingTextIsEmpty(): void
    {
        $config = $this->config([]);

        self::assertSame('', $config->getEndpointUrl());
        self::assertSame('', $config->getStoreCode());
    }

    /**
     * @param array<string,string> $values Values by path, at the default scope
     * @return GraphQlConfig
     */
    private function config(array $values): GraphQlConfig
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT) use ($values) {
                self::assertSame(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scope);

                return $values[$path] ?? null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT) use ($values) {
                self::assertSame(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scope);

                return (bool) ($values[$path] ?? false);
            }
        );

        return new GraphQlConfig($scopeConfig);
    }
}
