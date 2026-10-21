<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * JSON-RPC error codes the server answers with
 *
 * The negative codes from -32700 to -32600 are JSON-RPC's own. The server's codes are in the range JSON-RPC leaves to
 * implementations: the server is disabled, an ability call was denied, an ability call needs a confirmation.
 */
enum ErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case InvalidParams = -32602;
    case InternalError = -32603;
    case ServerDisabled = -32001;
    case AbilityDenied = -32003;
    case ConfirmationRequired = -32004;
}
