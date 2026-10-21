<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Method;

use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;
use Hryvinskyi\McpServer\Model\Protocol\JsonObjects;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\Reply;
use Hryvinskyi\McpServer\Model\Protocol\ReplyFactory;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolInterface;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolRegistry;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolResults;

/**
 * `tools/call`: runs the tool of the registry that has the name the client gave
 *
 * A request that names no tool, or whose `arguments` is not a JSON object, is Invalid params. An argument the tool
 * cannot take is a tool error, so the model reads why and corrects its call.
 */
class ToolsCall implements MethodHandlerInterface
{
    /**
     * @param ReplyFactory $replyFactory
     * @param ToolResults $toolResults
     * @param JsonObjects $jsonObjects
     * @param ToolRegistry $tools
     */
    public function __construct(
        private readonly ReplyFactory $replyFactory,
        private readonly ToolResults $toolResults,
        private readonly JsonObjects $jsonObjects,
        private readonly ToolRegistry $tools
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(Message $message, CallContext $context): Reply
    {
        $params = $message->getParams();
        $tool = $this->tool($params['name'] ?? null);

        $arguments = $params['arguments'] ?? [];
        if (!$this->jsonObjects->isObject($arguments)) {
            throw new JsonRpcErrorException(
                ErrorCode::InvalidParams,
                __('Invalid params: "arguments" must be a JSON object.')->render()
            );
        }

        try {
            $result = $tool->call($this->jsonObjects->members($arguments), $context);
        } catch (InvalidToolArgumentException $exception) {
            $result = $this->toolResults->error($exception->getMessage());
        }

        return $this->replyFactory->create()->withResult($result);
    }

    /**
     * The tool with this name
     *
     * @param mixed $name
     * @return ToolInterface
     * @throws JsonRpcErrorException When the name is no tool's
     */
    private function tool(mixed $name): ToolInterface
    {
        $tool = is_string($name) ? $this->tools->find($name) : null;
        if ($tool !== null) {
            return $tool;
        }

        throw new JsonRpcErrorException(
            ErrorCode::InvalidParams,
            __(
                'Invalid params: "%1" is not a tool. The tools are: %2.',
                is_scalar($name) ? (string) $name : '',
                implode(', ', $this->tools->names())
            )->render()
        );
    }

    /**
     * @inheritDoc
     */
    public function acceptsNotification(): bool
    {
        return false;
    }
}
