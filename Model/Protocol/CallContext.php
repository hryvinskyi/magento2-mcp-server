<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Model\Session\Session;

/**
 * What a method handler knows of the message it handles, created through CallContextFactory
 *
 * The session is the caller's own live session; it is null only while `initialize` opens one.
 */
class CallContext
{
    /**
     * @param TransportContext $transport
     * @param Session|null $session
     */
    public function __construct(
        private readonly TransportContext $transport,
        private readonly ?Session $session = null
    ) {
    }

    /**
     * What the transport knew of the request
     *
     * @return TransportContext
     */
    public function getTransport(): TransportContext
    {
        return $this->transport;
    }

    /**
     * The caller's session, or null while `initialize` opens one
     *
     * @return Session|null
     */
    public function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * The authenticated account the message runs as
     *
     * @return CallerInterface
     */
    public function getCaller(): CallerInterface
    {
        return $this->transport->getCaller();
    }
}
