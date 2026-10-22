<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

/**
 * The container names of the ACL instances every MCP check asks, for tests that drop the shared instances bound to
 * one caller before acting as another
 */
class McpAcl
{
    public const AUTHORIZATION = 'HryvinskyiMcpServerAuthorization';
    public const WEBAPI_AUTHORIZATION = 'HryvinskyiMcpServerWebapiAuthorization';
}
