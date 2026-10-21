<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Cron;

use Hryvinskyi\McpServer\Cron\CleanExpiredSessions;
use Hryvinskyi\McpServer\Model\Confirmation\DbConfirmationStore;
use Hryvinskyi\McpServer\Model\Session\DbSessionStore;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The hourly cleanup through the real container: expired sessions and confirmation tokens go, live ones stay, however
 * many batches it takes
 */
#[DbIsolation(true)]
class CleanExpiredSessionsTest extends TestCase
{
    public function testExpiredSessionsAreRemovedInBatchesAndLiveOnesKept(): void
    {
        $now = time();
        $expired = [];
        for ($i = 0; $i < 5; $i++) {
            $expired[] = $this->insert(sprintf('%032x', $i + 1), $now - 2 * 86400 - $i);
        }
        $live = $this->insert(str_repeat('f', 32), $now - 3600);

        $cron = Bootstrap::getObjectManager()->create(CleanExpiredSessions::class, ['batchSize' => 2]);
        self::assertInstanceOf(CleanExpiredSessions::class, $cron);
        $cron->execute();

        self::assertSame([$live], $this->storedIds(array_merge($expired, [$live])));
    }

    public function testExpiredConfirmationTokensAreRemovedAndLiveOnesKept(): void
    {
        $now = time();
        $expired = [];
        for ($i = 0; $i < 3; $i++) {
            $expired[] = $this->insertToken(sprintf('%032x', $i + 1), $now - 60 - $i);
        }
        $live = $this->insertToken(str_repeat('f', 32), $now + 300);

        $cron = Bootstrap::getObjectManager()->create(CleanExpiredSessions::class, ['batchSize' => 2]);
        self::assertInstanceOf(CleanExpiredSessions::class, $cron);
        $cron->execute();

        self::assertSame([$live], $this->storedTokens(array_merge($expired, [$live])));
    }

    public function testTheContainerBuildsTheCronJobWithItsDefaultBatch(): void
    {
        $expired = $this->insert(str_repeat('e', 32), time() - 86400);

        $this->service(CleanExpiredSessions::class)->execute();

        self::assertSame([], $this->storedIds([$expired]));
    }

    /**
     * Store a session row last seen at that moment
     *
     * @param string $id
     * @param int $lastSeenAt
     * @return string
     */
    private function insert(string $id, int $lastSeenAt): string
    {
        $resource = $this->service(ResourceConnection::class);
        $resource->getConnection()->insert($resource->getTableName(DbSessionStore::TABLE), [
            'session_id' => $id,
            'user_type' => UserContextInterface::USER_TYPE_ADMIN,
            'user_id' => 1,
            'client_name' => 'Test',
            'client_version' => '1',
            'protocol_version' => '2025-06-18',
            'created_at' => gmdate('Y-m-d H:i:s', $lastSeenAt),
            'last_seen_at' => gmdate('Y-m-d H:i:s', $lastSeenAt),
        ]);

        return $id;
    }

    /**
     * Store a confirmation token that expires at that moment
     *
     * @param string $token
     * @param int $expiresAt
     * @return string
     */
    private function insertToken(string $token, int $expiresAt): string
    {
        $resource = $this->service(ResourceConnection::class);
        $resource->getConnection()->insert($resource->getTableName(DbConfirmationStore::TABLE), [
            'token' => $token,
            'session_id' => str_repeat('a', 32),
            'user_type' => UserContextInterface::USER_TYPE_ADMIN,
            'user_id' => 1,
            'ability' => 'catalog.product_repository.save',
            'arguments_hash' => str_repeat('b', 64),
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
        ]);

        return $token;
    }

    /**
     * Which of these tokens still have a row, in order
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function storedTokens(array $tokens): array
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $stored = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName(DbConfirmationStore::TABLE), ['token'])
                ->where('token IN (?)', $tokens)
        );

        return array_values(array_filter($tokens, static fn (string $token): bool => in_array($token, $stored, true)));
    }

    /**
     * Which of these ids still have a row, in order
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private function storedIds(array $ids): array
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $stored = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName(DbSessionStore::TABLE), ['session_id'])
                ->where('session_id IN (?)', $ids)
        );

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $stored, true)));
    }

    /**
     * A service from the container, of the class asked for
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
