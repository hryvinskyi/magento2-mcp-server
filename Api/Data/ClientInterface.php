<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * The MCP client that sent an invocation on the caller's behalf, as it introduced itself
 *
 * Create one through ClientInterfaceFactory.
 *
 * @api
 */
interface ClientInterface
{
    /**
     * The longest client name, in characters
     */
    public const NAME_MAX_LENGTH = 255;

    /**
     * The longest client version, in characters
     */
    public const VERSION_MAX_LENGTH = 64;

    /**
     * Name the client gave, or '' when it gave none
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Version the client gave, or '' when it gave none
     *
     * @return string
     */
    public function getVersion(): string;
}
