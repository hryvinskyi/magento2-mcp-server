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
 * The protocol's answer to one request body, free of any transport, created through ProtocolAnswerFactory
 *
 * A transport translates the status into its own terms, sends the body as it is when there is one, and announces the
 * session id when there is one: only the answer to `initialize` carries a session id. An accepted or no-content
 * answer never has a body.
 */
class ProtocolAnswer
{
    /**
     * @param AnswerStatus $status
     * @param string $body The JSON body, or '' for none
     * @param string $sessionId The session the answer opened, or ''
     * @throws \InvalidArgumentException When the status allows no body and there is one, or the session id is not one
     */
    public function __construct(
        private readonly AnswerStatus $status,
        private readonly string $body = '',
        private readonly string $sessionId = ''
    ) {
        if ($body !== '' && !$status->allowsBody()) {
            throw new \InvalidArgumentException(sprintf('A %s answer has no body.', $status->value));
        }

        if ($sessionId !== '' && preg_match(Session::ID_PATTERN, $sessionId) !== 1) {
            throw new \InvalidArgumentException('An answer announces a session by a session id.');
        }
    }

    /**
     * What kind of answer it is
     *
     * @return AnswerStatus
     */
    public function getStatus(): AnswerStatus
    {
        return $this->status;
    }

    /**
     * The JSON body, or '' for none
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The session the answer opened, to announce to the client, or ''
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }
}
