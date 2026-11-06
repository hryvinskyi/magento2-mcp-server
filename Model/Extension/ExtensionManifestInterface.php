<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Extension;

use Magento\Framework\Exception\LocalizedException;

/**
 * The manifest of the Claude Desktop extension that connects Claude to one store's MCP endpoint
 *
 * The extension is named after the endpoint's host, so extensions for several copies of the store (production,
 * staging) can be installed side by side. The endpoint is a setting the user may change at install time, with the
 * store's endpoint as its default; the access token is a required, sensitive setting the manifest never carries a
 * value for. An endpoint the bundled bridge would refuse to start on, because it would send the token unencrypted
 * or cannot reach it at all, is refused here, so the admin learns why before installing anything.
 */
interface ExtensionManifestInterface
{
    /**
     * The manifest for an extension that reaches the MCP endpoint at this URL
     *
     * @param string $endpointUrl
     * @return array{
     *     manifest_version: string,
     *     name: string,
     *     display_name: string,
     *     version: string,
     *     description: string,
     *     author: array{name: string, url: string},
     *     server: array{
     *         type: string,
     *         entry_point: string,
     *         mcp_config: array{
     *             command: string,
     *             args: list<string>,
     *             env: array{MAGENTO_MCP_URL: string, MAGENTO_MCP_TOKEN: string}
     *         }
     *     },
     *     user_config: array{
     *         endpoint: array{
     *             type: string,
     *             title: string,
     *             description: string,
     *             required: bool,
     *             default: string
     *         },
     *         token: array{type: string, title: string, description: string, required: bool, sensitive: bool}
     *     },
     *     compatibility: array{platforms: list<string>, runtimes: array{node: string}}
     * }
     * @throws LocalizedException When the URL names no host, a host with no letter or digit in it, a scheme other than
     *     https or http, plain http to a host other than localhost, 127.0.0.1 or [::1], or a user name or password
     *     before the host
     */
    public function build(string $endpointUrl): array;

    /**
     * The host of this URL as an extension name suffix: lower case, each run of other signs than a-z and 0-9 a dash
     *
     * @param string $endpointUrl
     * @return string Never empty
     * @throws LocalizedException When the URL names no host, a host with no letter or digit in it, a scheme other than
     *     https or http, plain http to a host other than localhost, 127.0.0.1 or [::1], or a user name or password
     *     before the host
     */
    public function slug(string $endpointUrl): string;
}
