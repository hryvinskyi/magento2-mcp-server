<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Model\Session\Session;

/**
 * How one message is answered, before it gets the id of the request: a result or an error, as an immutable value
 * created through ReplyFactory
 *
 * A reply without an error code is a result reply; its result arrives through withResult() and is the empty JSON
 * object until then. A reply with an error code says what went wrong, and may carry error data through
 * withErrorData(). Both withers return a copy, so no client or service data passes through the object manager's
 * argument handling. Only a result reply may announce a session it opened.
 */
class Reply
{
    /**
     * The result of a result reply; null until one is given
     *
     * @var array<mixed>|\stdClass|null
     */
    private mixed $result = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $errorData = null;

    /**
     * @param ErrorCode|null $errorCode The error, or null for a result reply
     * @param string $errorMessage What went wrong; '' for a result reply
     * @param string $sessionId The session a result reply opened, or ''
     * @throws \InvalidArgumentException When an error has no message, a result has one, or an error opens a session
     */
    public function __construct(
        private readonly ?ErrorCode $errorCode = null,
        private readonly string $errorMessage = '',
        private readonly string $sessionId = ''
    ) {
        if (($errorCode === null) !== ($errorMessage === '')) {
            throw new \InvalidArgumentException('An error reply, and only an error reply, has a message.');
        }

        if ($errorCode !== null && $sessionId !== '') {
            throw new \InvalidArgumentException('An error reply opens no session.');
        }

        if ($sessionId !== '' && preg_match(Session::ID_PATTERN, $sessionId) !== 1) {
            throw new \InvalidArgumentException('A reply announces a session by a session id.');
        }
    }

    /**
     * Whether the reply is an error
     *
     * @return bool
     */
    public function isError(): bool
    {
        return $this->errorCode !== null;
    }

    /**
     * The result of a result reply; an empty JSON object until one is given
     *
     * @return array<mixed>|\stdClass
     */
    public function getResult(): array|\stdClass
    {
        return $this->result ?? (object) [];
    }

    /**
     * The error code, or null for a result reply
     *
     * @return ErrorCode|null
     */
    public function getErrorCode(): ?ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * What went wrong, or '' for a result reply
     *
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * The error's data, or null for none
     *
     * @return array<string, mixed>|null
     */
    public function getErrorData(): ?array
    {
        return $this->errorData;
    }

    /**
     * The session the reply opened, or ''
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * A copy of this result reply carrying the result; this one is left as it is
     *
     * An empty array is a JSON list. A result that may be an empty JSON object is given as an \stdClass.
     *
     * @param array<mixed>|\stdClass $result
     * @return Reply
     * @throws \LogicException When this is an error reply
     */
    public function withResult(array|\stdClass $result): Reply
    {
        if ($this->errorCode !== null) {
            throw new \LogicException('An error reply carries no result.');
        }

        $copy = clone $this;
        $copy->result = $result;

        return $copy;
    }

    /**
     * A copy of this error reply carrying the error's data; this one is left as it is
     *
     * @param array<string,mixed> $data
     * @return Reply
     * @throws \LogicException When this is a result reply
     */
    public function withErrorData(array $data): Reply
    {
        if ($this->errorCode === null) {
            throw new \LogicException('A result reply carries no error data.');
        }

        $copy = clone $this;
        $copy->errorData = $data;

        return $copy;
    }
}
