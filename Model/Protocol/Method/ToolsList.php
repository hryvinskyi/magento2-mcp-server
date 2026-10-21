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
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolRegistry;

/**
 * `tools/list`: every tool of the registry, on one page, with its input schema and annotations
 */
class ToolsList implements MethodHandlerInterface
{
    /**
     * @param ReplyFactory $replyFactory
     * @param ToolRegistry $tools
     */
    public function __construct(
        private readonly ReplyFactory $replyFactory,
        private readonly ToolRegistry $tools
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(Message $message, CallContext $context): Reply
    {
        $tools = [];
        foreach ($this->tools->all() as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
                'annotations' => $tool->getAnnotations(),
            ];
        }

        return $this->replyFactory->create()->withResult(['tools' => $tools]);
    }

    /**
     * @inheritDoc
     */
    public function acceptsNotification(): bool
    {
        return false;
    }
}
