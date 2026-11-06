<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * The configurations of Claude Desktop and Cursor, each over HTTP and over stdio
 *
 * - The server's name and endpoint come from the client connection, so every client is configured the same way.
 * - The token and the admin login are placeholders the admin fills in: no secret is ever part of a configuration.
 * - Claude Desktop reaches an HTTP server with a Bearer token through the `mcp-remote` bridge, which takes the header
 *   from an environment variable; Cursor takes the URL and headers itself.
 * - Over stdio both start the server's console command with the PHP binary on the machine Magento runs on.
 *
 * @phpstan-import-type Snippet from ClientSnippetsInterface
 */
class ClientSnippets implements ClientSnippetsInterface
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @param ClientConnectionInterface $connection
     * @param DirectoryList $directoryList
     */
    public function __construct(
        private readonly ClientConnectionInterface $connection,
        private readonly DirectoryList $directoryList
    ) {
    }

    /**
     * @inheritDoc
     */
    public function all(): array
    {
        $endpoint = $this->connection->endpointUrl();
        $name = $this->connection->serverName();
        $stdio = ['mcpServers' => [$name => [
            'command' => 'php',
            'args' => [
                $this->directoryList->getRoot() . '/bin/magento',
                self::SERVE_COMMAND,
                self::LOGIN_PLACEHOLDER,
            ],
        ]]];
        $httpHint = (string) __(
            'Paste the access token above to fill it in, or replace %1 with the access token of an integration '
            . '(System › Extensions › Integrations). Restart the client after saving.',
            self::TOKEN_PLACEHOLDER
        );
        $stdioHint = (string) __(
            'The client starts the command on the machine Magento runs on, as the admin user you name instead of '
            . '<login>; the role of that user decides what the client may do.'
        );
        $claudeFile = (string) __('claude_desktop_config.json (Claude › Settings › Developer › Edit Config)');
        $cursorFile = (string) __('.cursor/mcp.json in a project, or ~/.cursor/mcp.json for every project');

        return [
            $this->snippet('claude', 'http', $claudeFile, $httpHint, ['mcpServers' => [$name => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${MAGENTO_MCP_AUTH}'],
                'env' => ['MAGENTO_MCP_AUTH' => 'Bearer ' . self::TOKEN_PLACEHOLDER],
            ]]]),
            $this->snippet('claude', 'stdio', $claudeFile, $stdioHint, $stdio),
            $this->snippet('cursor', 'http', $cursorFile, $httpHint, ['mcpServers' => [$name => [
                'url' => $endpoint,
                'headers' => ['Authorization' => 'Bearer ' . self::TOKEN_PLACEHOLDER],
            ]]]),
            $this->snippet('cursor', 'stdio', $cursorFile, $stdioHint, $stdio),
        ];
    }

    /**
     * One client configuration
     *
     * @param string $client
     * @param string $transport
     * @param string $file
     * @param string $hint
     * @param array<string,mixed> $configuration
     * @return Snippet
     */
    private function snippet(string $client, string $transport, string $file, string $hint, array $configuration): array
    {
        return [
            'id' => $client . '-' . $transport,
            'client' => $client,
            'transport' => $transport,
            'file' => $file,
            'json' => json_encode($configuration, self::JSON_FLAGS),
            'hint' => $hint,
        ];
    }
}
