<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\ViewModel;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Api\PersonalDataExceptionsInterface;
use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;
use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Admin\ClientConnectionInterface;
use Hryvinskyi\McpServer\Model\Admin\ClientSnippetsInterface;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolVersions;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * What the MCP Server page shows above the catalog: the server's state and how a client connects to it
 *
 * - The server's name and endpoint come from the client connection, so the page configures every client the same
 *   way; the endpoint is shown, not edited.
 * - The client configurations come from their own owner and carry placeholders for the token and the admin login:
 *   no secret is ever printed into the page. A token pasted into the page stays in the browser; a token picked from
 *   the store's integrations comes from the store through the integration token route, never through the page.
 *   Either way the browser writes it into the install links, the command and the configurations.
 * - The Claude Desktop extension is downloaded from its own admin route and asks for the token when installed.
 *
 * @phpstan-import-type Snippet from ClientSnippetsInterface
 */
class ServerOverview implements ArgumentInterface
{
    private const CONFIG_RESOURCE = 'Hryvinskyi_McpServer::config';
    private const CONFIG_SECTION = 'hryvinskyi_mcp_server';

    /**
     * @param ConfigInterface $config
     * @param AbilityRows $rows
     * @param ProtocolVersions $protocolVersions
     * @param ClientConnectionInterface $connection
     * @param ClientSnippetsInterface $snippets
     * @param BackendUrl $backendUrl
     * @param AuthorizationInterface $authorization
     * @param JsonHexTag $json
     * @param PersonalDataPolicyInterface $personalData
     * @param PersonalDataExceptionsInterface $personalDataExceptions
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly AbilityRows $rows,
        private readonly ProtocolVersions $protocolVersions,
        private readonly ClientConnectionInterface $connection,
        private readonly ClientSnippetsInterface $snippets,
        private readonly BackendUrl $backendUrl,
        private readonly AuthorizationInterface $authorization,
        private readonly JsonHexTag $json,
        private readonly PersonalDataPolicyInterface $personalData,
        private readonly PersonalDataExceptionsInterface $personalDataExceptions
    ) {
    }

    /**
     * The x-magento-init JSON that mounts the connection card on the element the selector finds
     *
     * The script receives the wording a copy button reads once it copied, and the client connection: the server's
     * name, its endpoint and the token placeholder the page's configurations carry. From these it builds the install
     * links and the Claude Code command in the browser, with the token the token field holds. No token is in this
     * JSON, nor anywhere else PHP prints into the page. A token pasted by hand never reaches the store; a picked one
     * comes from the store through the integration token route, never through the page. Tags are hex-escaped, so the
     * JSON cannot end its script element.
     *
     * @param string $selector
     * @return string
     */
    public function mountConfig(string $selector): string
    {
        return (string) $this->json->serialize([
            $selector => ['Hryvinskyi_McpServer/js/connection-card' => [
                'copied' => (string) __('Copied'),
                'connection' => [
                    'name' => $this->connection->serverName(),
                    'url' => $this->connection->endpointUrl(),
                    'placeholder' => ClientSnippetsInterface::TOKEN_PLACEHOLDER,
                ],
            ]],
        ]);
    }

    /**
     * Where the admin downloads the Claude Desktop extension, which asks for the access token itself
     *
     * @return string
     */
    public function extensionUrl(): string
    {
        return $this->backendUrl->getUrl('hryvinskyi_mcp/server/extension');
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
        return $this->connection->endpointUrl();
    }

    /**
     * The console command that serves MCP over standard input and output
     *
     * @return string
     */
    public function stdioCommand(): string
    {
        return 'bin/magento ' . ClientSnippetsInterface::SERVE_COMMAND . ' '
            . ClientSnippetsInterface::LOGIN_PLACEHOLDER;
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
        return $this->rows->exposedCount();
    }

    /**
     * Where personal data stands: hidden, hidden but shown to some abilities, or shown to all
     *
     * @return string
     */
    public function personalDataText(): string
    {
        if (!$this->personalData->isHidingOn()) {
            return (string) __('Shown to every ability');
        }
        $shown = count($this->personalDataExceptions->shown());

        return (string) ($shown === 0 ? __('Hidden') : __('Hidden, shown to %1 abilities', $shown));
    }

    /**
     * Whether any ability sends personal data to clients
     *
     * @return bool
     */
    public function sendsPersonalData(): bool
    {
        return !$this->personalData->isHidingOn() || $this->personalDataExceptions->shown() !== [];
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
        return $this->snippets->all();
    }
}
