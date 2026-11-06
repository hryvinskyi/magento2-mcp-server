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
 * A client extension an admin downloads and installs to connect the client to this store's MCP endpoint
 *
 * It holds no secret: the client asks for the access token when the extension is installed.
 */
interface ExtensionPackageInterface
{
    /**
     * The name the downloaded file is saved under
     *
     * @return string
     * @throws LocalizedException When the store's endpoint names no host to name the extension after
     */
    public function fileName(): string;

    /**
     * The bytes of the file
     *
     * @return string
     * @throws LocalizedException When the store's endpoint names no host, or a file of the extension is missing or
     *     unreadable; the message names no path outside the module
     */
    public function content(): string;
}
