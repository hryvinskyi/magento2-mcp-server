<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Config\EndpointUrlValue;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfig;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The ACL resources and settings an admin sees, as Magento's own readers build them
 */
#[AppArea('adminhtml')]
class AdminSurfaceTest extends TestCase
{
    use BlocksRealGraphQlClient;

    public function testTheEndpointResourceSitsUnderTheServerUnderStores(): void
    {
        $stores = $this->resource($this->aclTree(), ['Magento_Backend::admin', 'Magento_Backend::stores']);
        $server = $this->resource($stores['children'], ['Hryvinskyi_McpServer::mcp_server']);
        $access = $this->resource($server['children'], ['Hryvinskyi_McpServer::access']);

        self::assertSame('MCP Server', $server['title']);
        self::assertSame('Use the MCP endpoint', $access['title']);
        self::assertSame(
            ['View the MCP Server page', 'Manage ability exposure'],
            [
                $this->resource($server['children'], ['Hryvinskyi_McpServer::view'])['title'],
                $this->resource($server['children'], ['Hryvinskyi_McpServer::manage'])['title'],
            ]
        );
    }

    public function testTheSettingsResourceSitsUnderConfiguration(): void
    {
        $config = $this->resource(
            $this->aclTree(),
            [
                'Magento_Backend::admin',
                'Magento_Backend::stores',
                'Magento_Backend::stores_settings',
                'Magento_Config::config',
            ]
        );

        self::assertSame(
            'MCP Server Settings',
            $this->resource($config['children'], ['Hryvinskyi_McpServer::config'])['title']
        );
    }

    public function testTheEndpointUrlIsSavedThroughItsCheckAndTheStoreViewIsChosenByCode(): void
    {
        $structure = Bootstrap::getObjectManager()->get(Structure::class);
        self::assertInstanceOf(Structure::class, $structure);

        $endpointUrl = $structure->getElement(GraphQlConfig::XML_PATH_ENDPOINT_URL);
        self::assertInstanceOf(Field::class, $endpointUrl);
        self::assertInstanceOf(EndpointUrlValue::class, $endpointUrl->getBackendModel());

        $storeCode = $structure->getElement(GraphQlConfig::XML_PATH_STORE_CODE);
        self::assertInstanceOf(Field::class, $storeCode);
        $values = array_column($storeCode->getOptions(), 'value');
        self::assertSame('', $values[0] ?? null);
        self::assertContains('default', $values);
    }

    /**
     * The merged ACL resource tree
     *
     * @return array<mixed>
     */
    private function aclTree(): array
    {
        $provider = Bootstrap::getObjectManager()->get(ProviderInterface::class);
        self::assertInstanceOf(ProviderInterface::class, $provider);

        return $provider->getAclResources();
    }

    /**
     * Walk down the tree along these ids and return the last resource
     *
     * @param array<mixed> $resources
     * @param non-empty-list<string> $path
     * @return array{title: string, children: array<mixed>}
     */
    private function resource(array $resources, array $path): array
    {
        $found = null;
        foreach ($path as $id) {
            $found = null;
            foreach ($resources as $resource) {
                if (is_array($resource) && ($resource['id'] ?? null) === $id) {
                    $found = $resource;
                    break;
                }
            }
            self::assertIsArray($found, sprintf('The ACL resource %s is missing.', $id));
            $children = $found['children'] ?? [];
            self::assertIsArray($children);
            $resources = $children;
        }

        $title = $found['title'] ?? '';
        self::assertIsString($title);

        return ['title' => $title, 'children' => $resources];
    }
}
