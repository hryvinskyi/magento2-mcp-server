<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;
use Hryvinskyi\McpServer\Model\Protocol\Method\MethodHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Hands one message to the handler of its method and says how it is answered
 *
 * - An invalid batch element is answered with an Invalid Request error.
 * - A client response is taken and never answered.
 * - A notification is handed to the handler of its method only when that handler accepts notifications, so a method
 *   that acts on request never runs from a notification. A notification is never answered, not even when no handler
 *   takes it or the handler fails; a failure is logged.
 * - A request is answered by its handler. A method no handler knows is Method not found, a JSON-RPC error the
 *   handler raises is answered as it is, and anything else the handler throws is logged and answered as an Internal
 *   error, so one failing request never takes down the others of its batch.
 */
class Dispatcher
{
    /**
     * @param ReplyFactory $replyFactory
     * @param LoggerInterface $logger
     * @param array<string,MethodHandlerInterface> $handlers Handlers keyed by the method they handle
     */
    public function __construct(
        private readonly ReplyFactory $replyFactory,
        private readonly LoggerInterface $logger,
        private readonly array $handlers = []
    ) {
    }

    /**
     * The reply to the message, or null when it is not answered
     *
     * @param Message $message
     * @param CallContext $context
     * @return Reply|null
     */
    public function answer(Message $message, CallContext $context): ?Reply
    {
        if ($message->getKind() === MessageKind::Invalid) {
            return $this->error(ErrorCode::InvalidRequest, $message->getError());
        }

        if ($message->getKind() === MessageKind::Response) {
            return null;
        }

        if ($message->getKind() === MessageKind::Notification) {
            $this->hear($message, $context);

            return null;
        }

        return $this->respond($message, $context);
    }

    /**
     * Answer a request through the handler of its method
     *
     * @param Message $message
     * @param CallContext $context
     * @return Reply
     */
    private function respond(Message $message, CallContext $context): Reply
    {
        $handler = $this->handlers[$message->getMethod()] ?? null;
        if ($handler === null) {
            return $this->error(
                ErrorCode::MethodNotFound,
                __('Method not found: %1.', $message->getMethod())->render()
            );
        }

        try {
            return $handler->handle($message, $context);
        } catch (JsonRpcErrorException $exception) {
            $reply = $this->error(
                $exception->getErrorCode(),
                $exception->getMessage() !== '' ? $exception->getMessage() : __('Internal error')->render()
            );
            $data = $exception->getData();

            return $data === null ? $reply : $reply->withErrorData($data);
        } catch (\Throwable $failure) {
            $this->logFailure($message, $failure);

            return $this->error(ErrorCode::InternalError, __('Internal error')->render());
        }
    }

    /**
     * Hand a notification to the handler of its method, when there is one and it accepts notifications
     *
     * @param Message $message
     * @param CallContext $context
     * @return void
     */
    private function hear(Message $message, CallContext $context): void
    {
        $handler = $this->handlers[$message->getMethod()] ?? null;
        if ($handler === null || !$handler->acceptsNotification()) {
            return;
        }

        try {
            $handler->handle($message, $context);
        } catch (\Throwable $failure) {
            $this->logFailure($message, $failure);
        }
    }

    /**
     * An error reply
     *
     * @param ErrorCode $code
     * @param string $message
     * @return Reply
     */
    private function error(ErrorCode $code, string $message): Reply
    {
        return $this->replyFactory->create(['errorCode' => $code, 'errorMessage' => $message]);
    }

    /**
     * Log what made a handler fail, with its trace
     *
     * @param Message $message
     * @param \Throwable $failure
     * @return void
     */
    private function logFailure(Message $message, \Throwable $failure): void
    {
        $this->logger->error(
            sprintf('MCP method "%s" failed: %s', $message->getMethod(), $failure->getMessage()),
            ['exception' => $failure]
        );
    }
}
