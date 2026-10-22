<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Transport;

/**
 * Whether the channel that carries the current MCP message keeps it private on the way, which Web API routes marked
 * secure require
 *
 * One instance is shared by the process: the transport that receives the message tells it what the channel is, and
 * whatever runs the message asks it.
 */
interface ChannelSecurityInterface
{
    /**
     * Whether the message came over a channel that keeps it private: a local pipe, or an HTTPS request
     *
     * @return bool
     */
    public function isSecure(): bool;

    /**
     * Record that this process receives its message over a local pipe, which never leaves the machine
     *
     * @return void
     */
    public function markLocalChannel(): void;
}
