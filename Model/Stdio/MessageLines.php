<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Hryvinskyi\McpServer\Model\Protocol\Answers;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Exception\RequestRejectedException;
use Hryvinskyi\McpServer\Model\Protocol\JsonRpcResponses;
use Hryvinskyi\McpServer\Model\Protocol\MessageKind;
use Hryvinskyi\McpServer\Model\Protocol\MessageParser;
use Hryvinskyi\McpServer\Model\Protocol\ParsedBody;

/**
 * What the serving loop needs to know about a line without handling it, read the way the protocol server reads it
 *
 * - The answer to a line whose message process failed keeps JSON-RPC's rules: every request in it gets an Internal
 *   error with its own id, so the client's pending request does not hang; a notification or a client response gets
 *   nothing; a batch gets the array of those errors, or nothing when it holds no request; a line the server would
 *   refuse as a whole (not JSON, too deep, not a message) gets one Internal error with the id null.
 * - The answer a message process wrote is re-addressed when it is the protocol server's refusal of the whole line —
 *   a single JSON object with `"jsonrpc":"2.0"`, `"id":null` and an `error` object whose `code` is an integer and
 *   `message` a string, its `data` absent or a JSON object (a missing or lost session, a message too large, an
 *   Internal error) — and the line holds requests: a client matches answers by id, so it would wait for its request
 *   until its own timeout. Each request of the line gets that error with its own id, its code, message and data
 *   kept; a batch gets the array of those errors; a line without requests gets nothing. Every other answer is
 *   written as it came, and so is a refusal of a line the server cannot read, which names no id to give.
 * - Only an answer that starts the way the module's JSON encoder writes such an error is decoded to check it; every
 *   other answer, a large result above all, is written without being read.
 * - Whether the line is the single `initialize` request that opens a session, or the single
 *   `notifications/initialized` notification that confirms one.
 */
class MessageLines
{
    private const INITIALIZE = 'initialize';
    private const INITIALIZED = 'notifications/initialized';
    private const ANSWER_DEPTH = 512;
    private const UNADDRESSED_ERROR_START = '{"jsonrpc":"2.0","id":null,"error":';

    /**
     * @param MessageParser $parser
     * @param JsonRpcResponses $responses
     * @param Answers $answers
     */
    public function __construct(
        private readonly MessageParser $parser,
        private readonly JsonRpcResponses $responses,
        private readonly Answers $answers
    ) {
    }

    /**
     * The body that answers the line when its message process failed, or '' when nothing may answer it
     *
     * @param string $line
     * @return string
     */
    public function failureAnswer(string $line): string
    {
        $message = __('Internal error')->render();
        $parsed = $this->parsed($line);
        if ($parsed === null) {
            return $this->answers->error(AnswerStatus::Ok, ErrorCode::InternalError, $message)->getBody();
        }

        return $this->errorPerRequest($parsed, ErrorCode::InternalError->value, $message);
    }

    /**
     * The body to write for the line, addressed to the line's requests when the server refused the line as a whole
     *
     * The answer as it came, or, when it is one error with the id null and the line holds requests, that error once
     * for each request with the request's own id ('' when the line holds none).
     *
     * @param string $line The line the message process read
     * @param string $body The answer the message process wrote
     * @return string
     */
    public function addressed(string $line, string $body): string
    {
        $error = $this->unaddressedError($body);
        if ($error === null) {
            return $body;
        }

        $parsed = $this->parsed($line);
        if ($parsed === null) {
            return $body;
        }

        return $this->errorPerRequest($parsed, $error['code'], $error['message'], $error['data']);
    }

    /**
     * Whether the line is a single `initialize` request
     *
     * @param string $line
     * @return bool
     */
    public function opensSession(string $line): bool
    {
        return $this->isSingle($line, MessageKind::Request, self::INITIALIZE);
    }

    /**
     * Whether the line is a single `notifications/initialized` notification
     *
     * @param string $line
     * @return bool
     */
    public function confirmsSession(string $line): bool
    {
        return $this->isSingle($line, MessageKind::Notification, self::INITIALIZED);
    }

    /**
     * Whether the line holds one message, not a batch, of this kind and method
     *
     * @param string $line
     * @param MessageKind $kind
     * @param string $method
     * @return bool
     */
    private function isSingle(string $line, MessageKind $kind, string $method): bool
    {
        $parsed = $this->parsed($line);
        if ($parsed === null || $parsed->isBatch()) {
            return false;
        }

        $message = $parsed->getMessages()[0];

        return $message->getKind() === $kind && $message->getMethod() === $method;
    }

    /**
     * This error once for each request of the line, with the request's own id, or '' when the line holds none
     *
     * @param ParsedBody $parsed
     * @param int $code
     * @param string $message
     * @param \stdClass|null $data The error's `data` member, or null for none
     * @return string
     */
    private function errorPerRequest(ParsedBody $parsed, int $code, string $message, ?\stdClass $data = null): string
    {
        $errors = [];
        foreach ($parsed->getMessages() as $request) {
            if ($request->getKind() === MessageKind::Request) {
                $errors[] = $this->responses->errorWithCode($request->getId(), $code, $message, $data);
            }
        }

        if ($errors === []) {
            return '';
        }

        return $parsed->isBatch()
            ? $this->answers->batch($errors)->getBody()
            : $this->answers->response($errors[0])->getBody();
    }

    /**
     * The error of an answer that refuses the whole line, or null for any other answer
     *
     * The answer is decoded only when it starts the way the module's encoder writes such an error. Decoded with JSON
     * objects kept as objects, the `data` member is passed on as it came, an empty object staying an object.
     *
     * @param string $body
     * @return array{code: int, message: string, data: \stdClass|null}|null
     */
    private function unaddressedError(string $body): ?array
    {
        if (!str_starts_with($body, self::UNADDRESSED_ERROR_START)) {
            return null;
        }

        try {
            $answer = json_decode($body, false, self::ANSWER_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!$answer instanceof \stdClass
            || ($answer->jsonrpc ?? null) !== '2.0'
            || !property_exists($answer, 'id')
            || $answer->id !== null
            || !($answer->error ?? null) instanceof \stdClass
        ) {
            return null;
        }

        $error = $answer->error;
        $code = $error->code ?? null;
        $message = $error->message ?? null;
        if (!is_int($code) || !is_string($message)) {
            return null;
        }

        if (!property_exists($error, 'data')) {
            return ['code' => $code, 'message' => $message, 'data' => null];
        }

        return $error->data instanceof \stdClass
            ? ['code' => $code, 'message' => $message, 'data' => $error->data]
            : null;
    }

    /**
     * The messages of the line, or null when the server would refuse it as a whole
     *
     * @param string $line
     * @return ParsedBody|null
     */
    private function parsed(string $line): ?ParsedBody
    {
        try {
            return $this->parser->parse($line);
        } catch (RequestRejectedException) {
            return null;
        }
    }
}
