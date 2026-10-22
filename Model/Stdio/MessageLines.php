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
 * - Whether the line is the single `initialize` request that opens a session, or the single
 *   `notifications/initialized` notification that confirms one.
 */
class MessageLines
{
    private const INITIALIZE = 'initialize';
    private const INITIALIZED = 'notifications/initialized';

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

        $errors = [];
        foreach ($parsed->getMessages() as $request) {
            if ($request->getKind() === MessageKind::Request) {
                $errors[] = $this->responses->error($request->getId(), ErrorCode::InternalError, $message);
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
