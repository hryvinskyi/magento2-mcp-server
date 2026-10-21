<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Session;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Model\Clock\WallClockInterface;
use Hryvinskyi\McpServer\Model\Db\ColumnValues;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Math\Random;
use Psr\Log\LoggerInterface;

/**
 * Keeps MCP sessions as rows of the session table, so neither a cache flush nor a disabled cache type ends them
 *
 * Times are stored as UTC date-times (see ColumnValues). A session whose last-seen time is a day old or more is never
 * returned, whether or not the cleanup has removed its row yet.
 *
 * One caller holds at most `maxSessionsPerCaller` sessions (a di.xml argument): opening one more deletes the caller's
 * least recently seen sessions beyond the cap, so a client that opens sessions in a loop cannot fill the table.
 *
 * A row that no longer makes a valid session (its caller or client was changed by hand) is logged and treated as
 * unknown.
 */
class DbSessionStore implements SessionStoreInterface
{
    public const TABLE = 'hryvinskyi_mcp_session';

    private const ID_LENGTH = 32;
    private const ID_CHARS = '0123456789abcdef';

    /**
     * @param ResourceConnection $resource
     * @param Random $random
     * @param WallClockInterface $clock
     * @param SessionFactory $sessionFactory
     * @param CallerInterfaceFactory $callerFactory
     * @param ClientInterfaceFactory $clientFactory
     * @param ColumnValues $columns
     * @param LoggerInterface $logger
     * @param int $maxSessionsPerCaller Live sessions one caller may hold, at least 1
     * @throws \InvalidArgumentException When the cap is below 1
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Random $random,
        private readonly WallClockInterface $clock,
        private readonly SessionFactory $sessionFactory,
        private readonly CallerInterfaceFactory $callerFactory,
        private readonly ClientInterfaceFactory $clientFactory,
        private readonly ColumnValues $columns,
        private readonly LoggerInterface $logger,
        private readonly int $maxSessionsPerCaller = 20
    ) {
        if ($maxSessionsPerCaller < 1) {
            throw new \InvalidArgumentException('A caller may hold at least 1 session.');
        }
    }

    /**
     * @inheritDoc
     */
    public function create(CallerInterface $caller, ClientInterface $client, string $protocolVersion): Session
    {
        $now = $this->clock->now();
        $session = $this->sessionFactory->create([
            'id' => $this->random->getRandomString(self::ID_LENGTH, self::ID_CHARS),
            'caller' => $caller,
            'client' => $client,
            'protocolVersion' => $protocolVersion,
            'createdAt' => $now,
            'lastSeenAt' => $now,
        ]);

        $this->connection()->insert($this->table(), [
            'session_id' => $session->getId(),
            'user_type' => $caller->getUserType(),
            'user_id' => $caller->getUserId(),
            'client_name' => $client->getName(),
            'client_version' => $client->getVersion(),
            'protocol_version' => $protocolVersion,
            'created_at' => $this->columns->dateTime($now),
            'last_seen_at' => $this->columns->dateTime($now),
        ]);
        $this->keepWithinCap($session);

        return $session;
    }

    /**
     * @inheritDoc
     */
    public function find(string $id): ?Session
    {
        if (preg_match(Session::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table())->where('session_id = ?', $id)
        );
        if (!is_array($row)) {
            return null;
        }

        $session = $this->sessionOf($row);
        if ($session === null || $session->isExpiredAt($this->clock->now())) {
            return null;
        }

        return $session;
    }

    /**
     * @inheritDoc
     */
    public function touch(Session $session): void
    {
        $now = $this->clock->now();
        if (!$session->needsTouchAt($now)) {
            return;
        }

        $this->connection()->update(
            $this->table(),
            ['last_seen_at' => $this->columns->dateTime($now)],
            ['session_id = ?' => $session->getId()]
        );
    }

    /**
     * @inheritDoc
     */
    public function delete(string $id): void
    {
        $this->connection()->delete($this->table(), ['session_id = ?' => $id]);
    }

    /**
     * @inheritDoc
     */
    public function deleteExpired(int $batch): int
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('Expired sessions are deleted in batches of at least 1.');
        }

        $connection = $this->connection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->table(), ['session_id'])
                ->where('last_seen_at <= ?', $this->columns->dateTime($this->clock->now() - Session::LIFETIME_SECONDS))
                ->order('last_seen_at ASC')
                ->limit($batch)
        );
        if ($ids === []) {
            return 0;
        }

        return $connection->delete($this->table(), ['session_id IN (?)' => $ids]);
    }

    /**
     * Delete the caller's least recently seen sessions beyond the cap, never the one just opened
     *
     * @param Session $opened
     * @return void
     */
    private function keepWithinCap(Session $opened): void
    {
        $connection = $this->connection();
        $caller = $opened->getCaller();
        $select = $connection->select()
            ->from($this->table(), ['session_id'])
            ->where('user_type = ?', $caller->getUserType())
            ->where('session_id <> ?', $opened->getId())
            ->order(['last_seen_at DESC', 'created_at DESC']);
        $userId = $caller->getUserId();
        if ($userId === null) {
            $select->where('user_id IS NULL');
        } else {
            $select->where('user_id = ?', $userId);
        }

        $beyondCap = array_slice($connection->fetchCol($select), $this->maxSessionsPerCaller - 1);
        if ($beyondCap !== []) {
            $connection->delete($this->table(), ['session_id IN (?)' => $beyondCap]);
        }
    }

    /**
     * The session a row holds, or null when the row no longer makes a valid one
     *
     * @param array<mixed> $row
     * @return Session|null
     */
    private function sessionOf(array $row): ?Session
    {
        try {
            return $this->sessionFactory->create([
                'id' => $this->columns->text($row, 'session_id'),
                'caller' => $this->callerFactory->create([
                    'userType' => (int) $this->columns->text($row, 'user_type'),
                    'userId' => $this->columns->nullableInt($row, 'user_id'),
                ]),
                'client' => $this->clientFactory->create([
                    'name' => $this->columns->text($row, 'client_name'),
                    'version' => $this->columns->text($row, 'client_version'),
                ]),
                'protocolVersion' => $this->columns->text($row, 'protocol_version'),
                'createdAt' => $this->columns->timestamp($row, 'created_at'),
                'lastSeenAt' => $this->columns->timestamp($row, 'last_seen_at'),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning(sprintf(
                'MCP session row "%s" is not a valid session: %s',
                $this->columns->text($row, 'session_id'),
                $exception->getMessage()
            ));

            return null;
        }
    }

    /**
     * The connection to the session table
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * The session table's name, with the installation's table prefix
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
