<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\ViewModel;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolVersions;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the MCP Server page shows above the catalog: the server's state and how a client connects to it
 *
 * - The endpoint is the default store view's secure base URL with the REST path of the MCP route; it is shown, not
 *   edited.
 * - A client configuration is given per client and transport. The token and the admin login are placeholders the
 *   admin fills in: no secret is ever printed into the page.
 * - Claude Desktop reaches an HTTP server with a Bearer token through the `mcp-remote` bridge; Cursor takes the URL
 *   and headers itself. Over stdio both start the server's console command with the PHP binary on the server.
 *
 * @phpstan-type Snippet array{id: string, client: string, transport: string, file: string, json: string, hint: string}
 */
class ServerOverview implements ArgumentInterface
{
    public const ENDPOINT_PATH = 'rest/all/V1/mcp';
    private const CONFIG_RESOURCE = 'Hryvinskyi_McpServer::config';
    private const CONFIG_SECTION = 'hryvinskyi_mcp_server';
    private const SERVE_COMMAND = 'hryvinskyi:mcp:serve';
    private const TOKEN_PLACEHOLDER = '<integration-token>';
    private const LOGIN_PLACEHOLDER = '--admin-user=<login>';
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @param ConfigInterface $config
     * @param AbilityRows $rows
     * @param ProtocolVersions $protocolVersions
     * @param StoreManagerInterface $storeManager
     * @param DirectoryList $directoryList
     * @param BackendUrl $backendUrl
     * @param AuthorizationInterface $authorization
     * @param JsonHexTag $json
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly AbilityRows $rows,
        private readonly ProtocolVersions $protocolVersions,
        private readonly StoreManagerInterface $storeManager,
        private readonly DirectoryList $directoryList,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
        private readonly JsonHexTag $json
    ) {
    }

    /**
     * The x-magento-init JSON that mounts the card's copy buttons on the element the selector finds
     *
     * It carries the wording a button reads once it copied; tags are hex-escaped, so the JSON cannot end its script
     * element.
     *
     * @param string $selector
     * @return string
     */
    public function mountConfig(string $selector): string
    {
        return (string) $this->json->serialize([
            $selector => ['Hryvinskyi_McpServer/js/connection-card' => ['copied' => (string) __('Copied')]],
        ]);
    }

    /**
     * Whether the server answers clients
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Whether read-only mode blocks every ability that does not only read
     *
     * @return bool
     */
    public function isReadOnlyMode(): bool
    {
        return $this->config->isReadOnlyMode();
    }

    /**
     * The server's state in a few words
     *
     * @return string
     */
    public function statusText(): string
    {
        if (!$this->isEnabled()) {
            return (string) __('Disabled');
        }

        return (string) ($this->isReadOnlyMode() ? __('Enabled · read-only mode') : __('Enabled'));
    }

    /**
     * The URL clients send MCP messages to
     *
     * @return string
     */
    public function endpointUrl(): string
    {
        $store = $this->storeManager->getDefaultStoreView();
        $base = $store instanceof Store ? (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true) : '';

        return rtrim($base, '/') . '/' . self::ENDPOINT_PATH;
    }

    /**
     * The console command that serves MCP over standard input and output
     *
     * @return string
     */
    public function stdioCommand(): string
    {
        return 'bin/magento ' . self::SERVE_COMMAND . ' ' . self::LOGIN_PLACEHOLDER;
    }

    /**
     * The newest MCP protocol version the server speaks
     *
     * @return string
     */
    public function protocolVersion(): string
    {
        return $this->protocolVersions->all()[0] ?? '';
    }

    /**
     * How many abilities the catalog holds
     *
     * @return int
     */
    public function abilityCount(): int
    {
        return count($this->rows->all());
    }

    /**
     * How many abilities a client may call, some after confirming
     *
     * @return int
     */
    public function exposedCount(): int
    {
        return count(array_filter(
            $this->rows->all(),
            static fn (array $row): bool => Exposure::from($row['exposure'])->allowsCall()
        ));
    }

    /**
     * The settings page of the server, or null when the admin may not open it
     *
     * @return string|null
     */
    public function configUrl(): ?string
    {
        return $this->authorization->isAllowed(self::CONFIG_RESOURCE)
            ? $this->backendUrl->getUrl('adminhtml/system_config/edit', ['section' => self::CONFIG_SECTION])
            : null;
    }

    /**
     * A client configuration per client and transport
     *
     * @return list<Snippet>
     */
    public function snippets(): array
    {
        $endpoint = $this->endpointUrl();
        $stdio = ['mcpServers' => ['magento' => [
            'command' => 'php',
            'args' => [
                $this->directoryList->getRoot() . '/bin/magento',
                self::SERVE_COMMAND,
                self::LOGIN_PLACEHOLDER,
            ],
        ]]];
        $httpHint = (string) __(
            'Replace %1 with the access token of an integration (System › Extensions › Integrations). Restart the '
            . 'client after saving.',
            self::TOKEN_PLACEHOLDER
        );
        $stdioHint = (string) __(
            'The client starts the command on the machine Magento runs on, as the admin user you name instead of '
            . '<login>; the role of that user decides what the client may do.'
        );
        $claudeFile = (string) __('claude_desktop_config.json (Claude › Settings › Developer › Edit Config)');
        $cursorFile = (string) __('.cursor/mcp.json in a project, or ~/.cursor/mcp.json for every project');

        return [
            $this->snippet('claude', 'http', $claudeFile, $httpHint, ['mcpServers' => ['magento' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${MAGENTO_MCP_AUTH}'],
                'env' => ['MAGENTO_MCP_AUTH' => 'Bearer ' . self::TOKEN_PLACEHOLDER],
            ]]]),
            $this->snippet('claude', 'stdio', $claudeFile, $stdioHint, $stdio),
            $this->snippet('cursor', 'http', $cursorFile, $httpHint, ['mcpServers' => ['magento' => [
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
