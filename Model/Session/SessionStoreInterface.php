<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Session;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;

/**
 * Where MCP sessions are kept between requests
 *
 * A session survives a cache flush: it ends when its client deletes it or a day after it was last seen.
 */
interface SessionStoreInterface
{
    /**
     * Open a session under a new random id
     *
     * A store may end the caller's least recently seen sessions, so that one caller cannot hold sessions without
     * limit; the session it opens is never one of them.
     *
     * @param CallerInterface $caller
     * @param ClientInterface $client
     * @param string $protocolVersion
     * @return Session
     */
    public function create(CallerInterface $caller, ClientInterface $client, string $protocolVersion): Session;

    /**
     * The live session with this id, or null when there is none or it has expired
     *
     * The id comes from a client, so any string is accepted, and one that cannot be a session id finds nothing.
     *
     * @param string $id
     * @return Session|null
     */
    public function find(string $id): ?Session;

    /**
     * Record that the session was seen now, unless that was already recorded less than a minute ago
     *
     * @param Session $session
     * @return void
     */
    public function touch(Session $session): void;

    /**
     * End the session; an unknown id is left as it is
     *
     * @param string $id
     * @return void
     */
    public function delete(string $id): void;

    /**
     * Delete up to this many expired sessions, oldest first
     *
     * @param int $batch At least 1
     * @return int How many were deleted; less than the batch once no expired session is left
     * @throws \InvalidArgumentException When the batch is below 1
     */
    public function deleteExpired(int $batch): int;
}
