<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;

/**
 * What a transport knows of a request before any session is looked up, created through TransportContextFactory
 *
 * The session id and the protocol version are passed on as the client sent them, '' when it sent none: telling a
 * malformed or unknown value apart is the server's job, which answers it in protocol terms. The context carries no
 * client: the client introduced itself in `initialize`, and only the session remembers it.
 */
class TransportContext
{
    private const TRANSPORT_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /**
     * @param CallerInterface $caller The authenticated account the request runs as
     * @param string $transport Lowercase code of the transport, such as `http`
     * @param string $sessionId The session id the client sent, or ''
     * @param string $protocolVersion The protocol version the client announced for this request, or ''
     * @throws \InvalidArgumentException When the transport is not a lowercase code
     */
    public function __construct(
        private readonly CallerInterface $caller,
        private readonly string $transport,
        private readonly string $sessionId = '',
        private readonly string $protocolVersion = ''
    ) {
        if (preg_match(self::TRANSPORT_PATTERN, $transport) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('The transport "%s" is not a lowercase code.', $transport)
            );
        }
    }

    /**
     * The authenticated account the request runs as
     *
     * @return CallerInterface
     */
    public function getCaller(): CallerInterface
    {
        return $this->caller;
    }

    /**
     * Lowercase code of the transport
     *
     * @return string
     */
    public function getTransport(): string
    {
        return $this->transport;
    }

    /**
     * The session id the client sent, or ''
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * The protocol version the client announced for this request, or ''
     *
     * @return string
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }
}
