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
 * One MCP session, opened by `initialize`, as an immutable value created through SessionFactory
 *
 * A session belongs to the caller who opened it: another caller holding its id is treated as if the session did not
 * exist. It expires a day after it was last seen, and being seen is recorded at most once a minute, so a busy client
 * does not write on every request.
 *
 * Times are seconds since the Unix epoch. The id is 32 lowercase hexadecimal characters.
 */
class Session
{
    /**
     * The format of every session id
     */
    public const ID_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * How long a session lives after it was last seen, in seconds
     */
    public const LIFETIME_SECONDS = 86400;

    /**
     * The least time between two records of a session being seen, in seconds
     */
    public const TOUCH_INTERVAL_SECONDS = 60;

    /**
     * The longest protocol version, in characters
     */
    public const PROTOCOL_VERSION_MAX_LENGTH = 16;

    /**
     * @param string $id
     * @param CallerInterface $caller
     * @param ClientInterface $client
     * @param string $protocolVersion
     * @param int $createdAt
     * @param int $lastSeenAt
     * @throws \InvalidArgumentException When the id, the version or the times are not valid
     */
    public function __construct(
        private readonly string $id,
        private readonly CallerInterface $caller,
        private readonly ClientInterface $client,
        private readonly string $protocolVersion,
        private readonly int $createdAt,
        private readonly int $lastSeenAt
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('A session id is 32 lowercase hexadecimal characters.');
        }

        if ($protocolVersion === '' || strlen($protocolVersion) > self::PROTOCOL_VERSION_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('A session protocol version is 1 to %d characters long.', self::PROTOCOL_VERSION_MAX_LENGTH)
            );
        }

        if ($createdAt < 0 || $lastSeenAt < $createdAt) {
            throw new \InvalidArgumentException('A session is last seen at or after the moment it was created.');
        }
    }

    /**
     * The session id the client sends back in every message
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The account that opened the session
     *
     * @return CallerInterface
     */
    public function getCaller(): CallerInterface
    {
        return $this->caller;
    }

    /**
     * The MCP client that opened the session, as it introduced itself
     *
     * @return ClientInterface
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * The protocol version negotiated by `initialize`
     *
     * @return string
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * When the session was opened
     *
     * @return int
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * When the session was last recorded as seen
     *
     * @return int
     */
    public function getLastSeenAt(): int
    {
        return $this->lastSeenAt;
    }

    /**
     * Whether the caller is the account that opened the session: the same user type and the same id
     *
     * @param CallerInterface $caller
     * @return bool
     */
    public function belongsTo(CallerInterface $caller): bool
    {
        return $caller->getUserType() === $this->caller->getUserType()
            && $caller->getUserId() === $this->caller->getUserId();
    }

    /**
     * Whether the session has expired at that moment
     *
     * @param int $now
     * @return bool
     */
    public function isExpiredAt(int $now): bool
    {
        return $now - $this->lastSeenAt >= self::LIFETIME_SECONDS;
    }

    /**
     * Whether a use at that moment should be recorded, because the last record is a minute old or more
     *
     * @param int $now
     * @return bool
     */
    public function needsTouchAt(int $now): bool
    {
        return $now - $this->lastSeenAt >= self::TOUCH_INTERVAL_SECONDS;
    }
}
