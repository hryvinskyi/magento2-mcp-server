<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin;

use Hryvinskyi\McpServer\Model\Admin\ClientConnectionInterface;
use Hryvinskyi\McpServer\Model\Admin\ClientSnippets;
use Magento\Framework\App\Filesystem\DirectoryList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Claude Desktop and Cursor each get a configuration over HTTP and over stdio, listing the server under the
 * connection's name. Over HTTP they carry the endpoint and a Bearer token placeholder, Claude Desktop through the
 * `mcp-remote` bridge; over stdio they start the server's console command with `php`, as an admin login placeholder.
 */
class ClientSnippetsTest extends TestCase
{
    private const NAME = 'shop-mcp';
    private const ENDPOINT = 'https://shop.example/rest/all/V1/mcp';
    private const ROOT = '/var/www/shop';

    public function testThereAreFourSnippetsPerClientAndTransportInPageOrder(): void
    {
        $snippets = $this->snippets()->all();

        self::assertSame(
            ['claude-http', 'claude-stdio', 'cursor-http', 'cursor-stdio'],
            array_column($snippets, 'id')
        );
        self::assertSame(['claude', 'claude', 'cursor', 'cursor'], array_column($snippets, 'client'));
        self::assertSame(['http', 'stdio', 'http', 'stdio'], array_column($snippets, 'transport'));
    }

    public function testEverySnippetIsJsonThatListsTheServerUnderTheConnectionsName(): void
    {
        foreach ($this->snippets()->all() as $snippet) {
            $servers = $this->servers($snippet['json']);

            self::assertSame([self::NAME], array_keys($servers), $snippet['id']);
        }
    }

    public function testClaudeDesktopOverHttpGoesThroughMcpRemoteWithTheBearerTokenInAnEnvironmentVariable(): void
    {
        self::assertSame(
            [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', self::ENDPOINT, '--header', 'Authorization:${MAGENTO_MCP_AUTH}'],
                'env' => ['MAGENTO_MCP_AUTH' => 'Bearer <integration-token>'],
            ],
            $this->server('claude-http')
        );
    }

    public function testCursorOverHttpTakesTheEndpointAndTheBearerTokenHeader(): void
    {
        self::assertSame(
            [
                'url' => self::ENDPOINT,
                'headers' => ['Authorization' => 'Bearer <integration-token>'],
            ],
            $this->server('cursor-http')
        );
    }

    public function testBothClientsOverStdioStartTheServeCommandWithPhpAsAnAdminLoginPlaceholder(): void
    {
        $expected = [
            'command' => 'php',
            'args' => [self::ROOT . '/bin/magento', 'hryvinskyi:mcp:serve', '--admin-user=<login>'],
        ];

        self::assertSame($expected, $this->server('claude-stdio'));
        self::assertSame($expected, $this->server('cursor-stdio'));
    }

    public function testTheJsonIsPrettyPrintedWithUnescapedSlashes(): void
    {
        $json = $this->snippet('cursor-http')['json'];

        self::assertStringContainsString('"url": "' . self::ENDPOINT . '"', $json);
        self::assertStringContainsString("\n    ", $json);
    }

    public function testTheHttpHintHoldsWhetherOrNotATokenWasPastedAndEachClientNamesItsFile(): void
    {
        foreach (['claude-http', 'cursor-http'] as $id) {
            $hint = $this->snippet($id)['hint'];

            self::assertStringContainsString('Paste the access token above to fill it in', $hint);
            self::assertStringContainsString('replace <integration-token> with the access token', $hint);
        }
        self::assertStringContainsString('<login>', $this->snippet('claude-stdio')['hint']);
        self::assertStringContainsString('claude_desktop_config.json', $this->snippet('claude-http')['file']);
        self::assertStringContainsString('.cursor/mcp.json', $this->snippet('cursor-stdio')['file']);
    }

    /**
     * The server entry of the snippet with the given id
     *
     * @param string $id
     * @return array<mixed>
     */
    private function server(string $id): array
    {
        $server = $this->servers($this->snippet($id)['json'])[self::NAME] ?? null;
        self::assertIsArray($server);

        return $server;
    }

    /**
     * The `mcpServers` object of a configuration
     *
     * @param string $json
     * @return array<mixed>
     */
    private function servers(string $json): array
    {
        $configuration = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($configuration);
        self::assertSame(['mcpServers'], array_keys($configuration));
        $servers = $configuration['mcpServers'];
        self::assertIsArray($servers);

        return $servers;
    }

    /**
     * The snippet with the given id
     *
     * @param string $id
     * @return array{id: string, client: string, transport: string, file: string, json: string, hint: string}
     */
    private function snippet(string $id): array
    {
        foreach ($this->snippets()->all() as $snippet) {
            if ($snippet['id'] === $id) {
                return $snippet;
            }
        }
        self::fail('No snippet ' . $id);
    }

    /**
     * The snippets of a connection named `shop-mcp` at the test endpoint, for a Magento installed at the test root
     *
     * @return ClientSnippets
     */
    private function snippets(): ClientSnippets
    {
        /** @var ClientConnectionInterface&MockObject $connection */
        $connection = $this->createMock(ClientConnectionInterface::class);
        $connection->method('serverName')->willReturn(self::NAME);
        $connection->method('endpointUrl')->willReturn(self::ENDPOINT);
        /** @var DirectoryList&MockObject $directoryList */
        $directoryList = $this->createMock(DirectoryList::class);
        $directoryList->method('getRoot')->willReturn(self::ROOT);

        return new ClientSnippets($connection, $directoryList);
    }
}
