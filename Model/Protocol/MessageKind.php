<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * The kinds of JSON-RPC message a client sends
 *
 * - request: a method and an id, answered with a response;
 * - notification: a method without an id, never answered;
 * - response: a client's answer to a server request (a result or an error, no method), never answered;
 * - invalid: an element of a batch that is none of these, answered with an Invalid Request error.
 */
enum MessageKind: string
{
    case Request = 'request';
    case Notification = 'notification';
    case Response = 'response';
    case Invalid = 'invalid';
}
