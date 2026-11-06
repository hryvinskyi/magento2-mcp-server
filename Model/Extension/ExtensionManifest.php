<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Extension;

use Hryvinskyi\McpServer\Model\Http\UrlHost;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * An MCPB manifest (format 0.3) for the bundled Node bridge, which relays Claude Desktop's stdio to the endpoint
 *
 * Claude Desktop fills the `${…}` placeholders itself when it starts the bridge: `${__dirname}` with the folder the
 * extension is unpacked in, and `${user_config.…}` with what the user entered at install time.
 */
class ExtensionManifest implements ExtensionManifestInterface
{
    /**
     * The extension's own version, independent of the module's
     *
     * Raise it only when the bridge under extension/server or the manifest this class builds changes, so Claude
     * Desktop sees a new extension exactly when there is one.
     */
    public const VERSION = '1.0.0';

    private const MANIFEST_VERSION = '0.3';
    private const NAME_PREFIX = 'magento-mcp-';
    private const DISPLAY_NAME_PREFIX = 'Magento MCP · ';
    private const AUTHOR = ['name' => 'Volodymyr Hryvinskyi', 'url' => 'https://github.com/hryvinskyi'];
    private const ENTRY_POINT = 'server/index.js';
    private const PLATFORMS = ['darwin', 'win32', 'linux'];
    private const NODE_VERSION = '>=18.0.0';

    /**
     * The hosts the bridge talks to over plain http, because a request to them never leaves the user's computer
     */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * A user name or password between the scheme and the host: the bridge refuses such an endpoint at start
     */
    private const USER_INFO_PATTERN = '~^[a-z][a-z0-9+.\-]*://[^/?#]*@~i';

    /**
     * @param UrlHost $urlHost
     */
    public function __construct(
        private readonly UrlHost $urlHost
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(string $endpointUrl): array
    {
        $host = $this->host($endpointUrl);

        return [
            'manifest_version' => self::MANIFEST_VERSION,
            'name' => self::NAME_PREFIX . $this->slug($endpointUrl),
            'display_name' => self::DISPLAY_NAME_PREFIX . $host,
            'version' => self::VERSION,
            'description' => (string) __('Connects Claude to the Magento store at %1 through its MCP server.', $host),
            'author' => self::AUTHOR,
            'server' => [
                'type' => 'node',
                'entry_point' => self::ENTRY_POINT,
                'mcp_config' => [
                    'command' => 'node',
                    'args' => ['${__dirname}/' . self::ENTRY_POINT],
                    'env' => [
                        'MAGENTO_MCP_URL' => '${user_config.endpoint}',
                        'MAGENTO_MCP_TOKEN' => '${user_config.token}',
                    ],
                ],
            ],
            'user_config' => [
                'endpoint' => [
                    'type' => 'string',
                    'title' => (string) __('Store endpoint'),
                    'description' => (string) __(
                        'The MCP endpoint of the store. Change it only to point this extension at another copy of '
                        . 'the store.'
                    ),
                    'required' => true,
                    'default' => $endpointUrl,
                ],
                'token' => [
                    'type' => 'string',
                    'title' => (string) __('Access token'),
                    'description' => (string) __(
                        'An integration\'s access token with the "Use the MCP endpoint" resource. Claude keeps it '
                        . 'in the system\'s keychain.'
                    ),
                    'required' => true,
                    'sensitive' => true,
                ],
            ],
            'compatibility' => [
                'platforms' => self::PLATFORMS,
                'runtimes' => ['node' => self::NODE_VERSION],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function slug(string $endpointUrl): string
    {
        $slug = trim((string) preg_replace('~[^a-z0-9]+~', '-', $this->host($endpointUrl)), '-');
        if ($slug === '') {
            $this->refuse(
                __(
                    'The host of the store\'s endpoint %1 has no letter or digit, so the extension cannot be named '
                    . 'after it. Set the default store view\'s secure base URL to an address with a host name.',
                    $endpointUrl
                )
            );
        }

        return $slug;
    }

    /**
     * The endpoint's lowercase host, once it is clear the bridge will reach it without exposing the token
     *
     * The bridge starts only on an https endpoint, or on a plain http one whose host is this computer, and never on
     * one carrying a user name or password; for any other endpoint the download would install an extension that
     * refuses to start.
     *
     * @param string $endpointUrl
     * @return string
     * @throws LocalizedException When the URL names no host, or the bridge would refuse to reach it
     */
    private function host(string $endpointUrl): string
    {
        $host = $this->urlHost->of($endpointUrl) ?? $this->refuse(
            __(
                'The store\'s endpoint %1 names no host, so the extension cannot point at it. Set the default store '
                . 'view\'s secure base URL.',
                $endpointUrl
            )
        );
        if (preg_match(self::USER_INFO_PATTERN, trim($endpointUrl)) === 1) {
            $this->refuse(
                __(
                    'The store\'s endpoint %1 carries a user name or a password, which the extension refuses to use. '
                    . 'Set the default store view\'s secure base URL without them.',
                    $endpointUrl
                )
            );
        }

        $scheme = strtolower((string) strstr(trim($endpointUrl), '://', true));
        if ($scheme === 'https') {
            return $host;
        }

        if ($scheme !== 'http') {
            $this->refuse(
                __(
                    'The store\'s endpoint %1 is neither an https nor an http address, so the extension cannot reach '
                    . 'it. Set the default store view\'s secure base URL to an https address.',
                    $endpointUrl
                )
            );
        }

        if (!in_array($host, self::LOOPBACK_HOSTS, true)) {
            $this->refuse(
                __(
                    'The store\'s endpoint %1 uses plain http to a host other than this computer, so the extension '
                    . 'would send the access token unencrypted and refuses to start. Set the default store view\'s '
                    . 'secure base URL to an https address.',
                    $endpointUrl
                )
            );
        }

        return $host;
    }

    /**
     * Refuse an endpoint the extension cannot be named after or pointed at, telling the admin why
     *
     * @param Phrase $reason
     * @return never
     * @throws LocalizedException Always
     */
    private function refuse(Phrase $reason): never
    {
        throw new LocalizedException($reason);
    }
}
