<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\Exception\RequestRejectedException;
use Hryvinskyi\McpServer\Model\Session\Session;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;

/**
 * Lets a message after `initialize` through only with the caller's own live session
 *
 * - No session id at all is a bad request: the client skipped `initialize`.
 * - A protocol version the server does not speak is a bad request.
 * - An id that names no live session, or a session another caller opened, is not found, with the same message in
 *   both cases, so nobody learns that someone else's session exists. The client then opens a new session.
 * - A protocol version other than the one the session negotiated is a bad request. A request without a version is
 *   fine.
 */
class SessionGate
{
    /**
     * @param SessionStoreInterface $sessions
     * @param ProtocolVersions $versions
     */
    public function __construct(
        private readonly SessionStoreInterface $sessions,
        private readonly ProtocolVersions $versions
    ) {
    }

    /**
     * The caller's live session that the request names
     *
     * @param TransportContext $context
     * @return Session
     * @throws RequestRejectedException When the request names no such session or speaks another protocol version
     */
    public function admit(TransportContext $context): Session
    {
        $id = $context->getSessionId();
        if ($id === '') {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('Bad Request: the Mcp-Session-Id header is missing. Send initialize first.')->render()
            );
        }

        $version = $context->getProtocolVersion();
        if ($version !== '' && !$this->versions->supports($version)) {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __(
                    'Bad Request: the MCP protocol version "%1" is not supported. Supported versions: %2.',
                    $version,
                    implode(', ', $this->versions->all())
                )->render()
            );
        }

        $session = $this->sessions->find($id);
        if ($session === null || !$session->belongsTo($context->getCaller())) {
            throw new RequestRejectedException(
                AnswerStatus::NotFound,
                ErrorCode::InvalidRequest,
                __('Session not found: it is unknown or expired. Send initialize to open a new one.')->render()
            );
        }

        if ($version !== '' && $version !== $session->getProtocolVersion()) {
            throw new RequestRejectedException(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __(
                    'Bad Request: the MCP protocol version "%1" differs from the version "%2" of this session.',
                    $version,
                    $session->getProtocolVersion()
                )->render()
            );
        }

        return $session;
    }
}
