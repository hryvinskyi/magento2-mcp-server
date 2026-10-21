<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\Exception\RequestRejectedException;

/**
 * Reads a request body as JSON-RPC 2.0 messages
 *
 * - A body that is not JSON is a Parse error, and JSON that is neither an object nor an array is an Invalid Request;
 *   both refuse the whole body.
 * - JSON nested more than 64 levels deep is an Invalid Request that refuses the whole body, so a client cannot make
 *   the decoder recurse without limit.
 * - A body that is a JSON array is a batch. An empty batch, or one with more elements than `maxBatchSize` (a di.xml
 *   argument), refuses the whole body; an element that is not a valid message becomes an invalid message, answered
 *   on its own, and the other elements are still handled.
 * - A single message that is not valid refuses the whole body as an Invalid Request.
 * - A message with a method is a request when it has an id, a string or a number, and a notification when it has
 *   none; an id that is null or of another type makes it invalid. A message without a method that holds a result or
 *   an error is a client response.
 * - Params, when present, are a JSON object.
 */
class MessageParser
{
    private const JSON_DEPTH = 64;
    private const JSON_WHITESPACE = " \t\n\r";

    /**
     * @param MessageFactory $messageFactory
     * @param ParsedBodyFactory $bodyFactory
     * @param JsonObjects $jsonObjects
     * @param int $maxBatchSize The most messages one batch may hold, at least 1
     * @throws \InvalidArgumentException When the batch size is below 1
     */
    public function __construct(
        private readonly MessageFactory $messageFactory,
        private readonly ParsedBodyFactory $bodyFactory,
        private readonly JsonObjects $jsonObjects,
        private readonly int $maxBatchSize = 20
    ) {
        if ($maxBatchSize < 1) {
            throw new \InvalidArgumentException('A batch may hold at least 1 message.');
        }
    }

    /**
     * The messages of the body
     *
     * @param string $body
     * @return ParsedBody
     * @throws RequestRejectedException When the body as a whole cannot be accepted
     */
    public function parse(string $body): ParsedBody
    {
        try {
            $decoded = json_decode($body, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new RequestRejectedException(
                    AnswerStatus::BadRequest,
                    ErrorCode::InvalidRequest,
                    __('Invalid Request: the body is nested more than %1 levels deep.', self::JSON_DEPTH)->render()
                );
            }

            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::ParseError,
                __('Parse error: the body is not valid JSON.')->render()
            );
        }

        if (!is_array($decoded)) {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('Invalid Request: the body is neither a JSON-RPC message nor a batch of them.')->render()
            );
        }

        if (str_starts_with(ltrim($body, self::JSON_WHITESPACE), '[')) {
            return $this->batch($decoded);
        }

        $message = $this->message($decoded);
        if ($message->getKind() === MessageKind::Invalid) {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                $message->getError()
            );
        }

        return $this->bodyFactory->create(['batch' => false])->withMessages([$message]);
    }

    /**
     * The messages of a batch, one per element
     *
     * @param array<mixed> $elements
     * @return ParsedBody
     * @throws RequestRejectedException When the batch is empty or holds too many messages
     */
    private function batch(array $elements): ParsedBody
    {
        if ($elements === []) {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('Invalid Request: a batch holds at least one message.')->render()
            );
        }

        if (count($elements) > $this->maxBatchSize) {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('Invalid Request: a batch holds at most %1 messages.', $this->maxBatchSize)->render()
            );
        }

        $messages = [];
        foreach ($elements as $element) {
            $messages[] = $this->message($element);
        }

        return $this->bodyFactory->create(['batch' => true])->withMessages($messages);
    }

    /**
     * The message one decoded element holds, or an invalid message saying why it holds none
     *
     * @param mixed $element
     * @return Message
     */
    private function message(mixed $element): Message
    {
        if (!$this->jsonObjects->isObject($element)) {
            return $this->invalid(__('Invalid Request: a message is a JSON object.')->render());
        }

        if (($element['jsonrpc'] ?? null) !== '2.0') {
            return $this->invalid(__('Invalid Request: "jsonrpc" must be "2.0".')->render());
        }

        if (!array_key_exists('method', $element)) {
            return $this->response($element);
        }

        $method = $element['method'];
        if (!is_string($method) || $method === '') {
            return $this->invalid(__('Invalid Request: "method" must be a non-empty string.')->render());
        }

        $params = $element['params'] ?? [];
        if (!$this->jsonObjects->isObject($params)) {
            return $this->invalid(__('Invalid Request: "params" must be a JSON object.')->render());
        }

        if (!array_key_exists('id', $element)) {
            return $this->messageFactory->create(['kind' => MessageKind::Notification, 'method' => $method])
                ->withParams($this->jsonObjects->members($params));
        }

        $id = $element['id'];
        if (!is_int($id) && !is_string($id)) {
            return $this->invalid(__('Invalid Request: a request "id" must be a string or an integer.')->render());
        }

        return $this->messageFactory->create(['kind' => MessageKind::Request, 'method' => $method, 'id' => $id])
            ->withParams($this->jsonObjects->members($params));
    }

    /**
     * The client response an element without a method holds, or an invalid message
     *
     * @param array<mixed> $element
     * @return Message
     */
    private function response(array $element): Message
    {
        if (!array_key_exists('result', $element) && !array_key_exists('error', $element)) {
            return $this->invalid(
                __('Invalid Request: a message has a "method", or a "result" or "error" when it is a response.')
                    ->render()
            );
        }

        $id = $element['id'] ?? null;

        return $this->messageFactory->create([
            'kind' => MessageKind::Response,
            'id' => is_int($id) || is_string($id) ? $id : null,
        ]);
    }

    /**
     * An invalid message with its reason
     *
     * @param string $reason
     * @return Message
     */
    private function invalid(string $reason): Message
    {
        return $this->messageFactory->create(['kind' => MessageKind::Invalid, 'error' => $reason]);
    }
}
