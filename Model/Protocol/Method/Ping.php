<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Method;

use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\Reply;
use Hryvinskyi\McpServer\Model\Protocol\ReplyFactory;

/**
 * `ping`: answered with an empty JSON object, which is how MCP says the server is alive
 */
class Ping implements MethodHandlerInterface
{
    /**
     * @param ReplyFactory $replyFactory
     */
    public function __construct(
        private readonly ReplyFactory $replyFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(Message $message, CallContext $context): Reply
    {
        return $this->replyFactory->create()->withResult((object) []);
    }

    /**
     * @inheritDoc
     */
    public function acceptsNotification(): bool
    {
        return false;
    }
}
