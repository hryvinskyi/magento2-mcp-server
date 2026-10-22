<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Stores › MCP Server through the real admin stack: the server's state, how a client connects, and the catalog
 *
 * The GraphQL abilities are switched off, so building the catalog reads no schema over the network.
 */
#[DbIsolation(true)]
class ServerPageTest extends AbstractBackendController
{
    use SwitchesGraphQlSourceOff;

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::view';

    /**
     * @var string
     */
    protected $uri = 'backend/mcpserver/server/index';

    #[Config('hryvinskyi_mcp_server/general/enabled', '0')]
    public function testThePageShowsHowToConnectAndNeverASecret(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        self::assertStringContainsString('/rest/all/V1/mcp', $body);
        self::assertStringContainsString('hryvinskyi:mcp:serve --admin-user=&lt;login&gt;', $body);
        self::assertSame(4, preg_match_all('/data-snippet="(claude|cursor)-(http|stdio)"/', $body));
        self::assertStringContainsString('Bearer &lt;integration-token&gt;', $body);
        self::assertStringContainsString('mcp-remote', $body, 'Claude Desktop reaches HTTP servers through a bridge');
        self::assertStringContainsString('The MCP server is disabled', $body);
        self::assertStringContainsString('hryvinskyi_mcp_ability_listing', $body);
        $card = $this->mountConfig($body)['[data-role="mcp-connection-card"]'] ?? null;
        self::assertIsArray($card);
        self::assertSame(['copied' => 'Copied'], $card['Hryvinskyi_McpServer/js/connection-card'] ?? null);
    }

    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    #[Config('hryvinskyi_mcp_server/general/read_only_mode', '1')]
    public function testAnEnabledServerInReadOnlyModeSaysSo(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        self::assertStringContainsString('Enabled · read-only mode', $body);
        self::assertStringContainsString('Read-only mode is on', $body);
        self::assertStringNotContainsString('The MCP server is disabled', $body);
    }

    /**
     * The x-magento-init config of the page that mounts the connection card
     *
     * @param string $body
     * @return array<mixed>
     */
    private function mountConfig(string $body): array
    {
        preg_match_all('#<script type="text/x-magento-init">(.*?)</script>#s', $body, $scripts);
        foreach ($scripts[1] as $script) {
            $config = json_decode($script, true);
            if (is_array($config) && isset($config['[data-role="mcp-connection-card"]'])) {
                return $config;
            }
        }

        self::fail('The page does not mount the connection card.');
    }

    /**
     * The page, which must have been answered
     *
     * @return string
     */
    private function body(): string
    {
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return (string) $response->getBody();
    }
}
