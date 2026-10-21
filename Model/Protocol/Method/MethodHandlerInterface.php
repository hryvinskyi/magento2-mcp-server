<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Method;

use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\Reply;

/**
 * Handles one MCP method; the handlers are the di.xml pool `handlers` of the dispatcher, keyed by method name
 *
 * A handler is called for every request of its method. It is called for a notification of its method only when it
 * says it accepts one, so a method that does something on request (calling a tool, opening a session) never runs
 * from a notification, which nobody answers. What a handler replies to a notification is never sent.
 */
interface MethodHandlerInterface
{
    /**
     * The reply to the message
     *
     * @param Message $message
     * @param CallContext $context
     * @return Reply
     * @throws JsonRpcErrorException When the request is answered with a JSON-RPC error
     */
    public function handle(Message $message, CallContext $context): Reply;

    /**
     * Whether a notification of the method is handed to the handler
     *
     * A notification the handler does not accept is taken, and nothing runs.
     *
     * @return bool
     */
    public function acceptsNotification(): bool;
}
