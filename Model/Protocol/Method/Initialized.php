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
 * `notifications/initialized`: the client is ready; the server keeps no state that waits for it, so nothing happens
 *
 * It is the one method a notification is handed over for.
 */
class Initialized implements MethodHandlerInterface
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
        return $this->replyFactory->create();
    }

    /**
     * @inheritDoc
     */
    public function acceptsNotification(): bool
    {
        return true;
    }
}
