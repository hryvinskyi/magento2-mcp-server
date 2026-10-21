<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Session;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Model\Session\DbSessionStore;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;
use Hryvinskyi\McpServer\Test\Integration\Fake\FixedWallClock;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The session table through the store the container builds, with the time of day under the test's control, and the
 * cap on the sessions one caller holds
 */
#[DbIsolation(true)]
class DbSessionStoreTest extends TestCase
{
    private const START = 1_800_000_000;
    private const DAY = 86400;

    /**
     * @var FixedWallClock
     */
    private FixedWallClock $clock;

    /**
     * @var DbSessionStore
     */
    private DbSessionStore $store;

    protected function setUp(): void
    {
        $this->clock = new FixedWallClock(self::START);
        $store = Bootstrap::getObjectManager()->create(DbSessionStore::class, ['clock' => $this->clock]);
        self::assertInstanceOf(DbSessionStore::class, $store);
        $this->store = $store;
    }

    public function testTheContainerKeepsSessionsInTheDatabase(): void
    {
        self::assertInstanceOf(
            DbSessionStore::class,
            Bootstrap::getObjectManager()->get(SessionStoreInterface::class)
        );
    }

    public function testACreatedSessionComesBackAsItWasOpened(): void
    {
        $created = $this->store->create($this->admin(1), $this->client('Example Client', '2.1.0'), '2025-06-18');

        $found = $this->store->find($created->getId());

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $created->getId());
        self::assertNotNull($found);
        self::assertSame($created->getId(), $found->getId());
        self::assertSame(UserContextInterface::USER_TYPE_ADMIN, $found->getCaller()->getUserType());
        self::assertSame(1, $found->getCaller()->getUserId());
        self::assertSame('Example Client', $found->getClient()->getName());
        self::assertSame('2.1.0', $found->getClient()->getVersion());
        self::assertSame('2025-06-18', $found->getProtocolVersion());
        self::assertSame(self::START, $found->getCreatedAt());
        self::assertSame(self::START, $found->getLastSeenAt());
        self::assertSame(
            ['created_at' => '2027-01-15 08:00:00', 'last_seen_at' => '2027-01-15 08:00:00'],
            $this->storedTimes($created->getId())
        );
    }

    public function testAGuestSessionKeepsNoUserId(): void
    {
        $guest = $this->callerFactory()->create(['userType' => UserContextInterface::USER_TYPE_GUEST]);

        $found = $this->store->find($this->store->create($guest, $this->client('', ''), '2024-11-05')->getId());

        self::assertNotNull($found);
        self::assertSame(UserContextInterface::USER_TYPE_GUEST, $found->getCaller()->getUserType());
        self::assertNull($found->getCaller()->getUserId());
    }

    public function testEverySessionGetsItsOwnId(): void
    {
        $first = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');
        $second = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');

        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testAnUnknownOrMalformedIdFindsNothing(): void
    {
        $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');

        self::assertNull($this->store->find(str_repeat('0', 32)));
        self::assertNull($this->store->find("' OR '1'='1"));
        self::assertNull($this->store->find(''));
    }

    public function testASessionExpiresADayAfterItWasLastSeen(): void
    {
        $id = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18')->getId();

        $this->clock->now = self::START + self::DAY - 1;
        self::assertNotNull($this->store->find($id));

        $this->clock->now = self::START + self::DAY;
        self::assertNull($this->store->find($id));
    }

    public function testUseIsWrittenAtMostOnceAMinuteAndKeepsTheSessionAlive(): void
    {
        $session = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');

        $this->clock->now = self::START + 59;
        $this->store->touch($session);
        self::assertSame('2027-01-15 08:00:00', $this->storedTimes($session->getId())['last_seen_at']);

        $this->clock->now = self::START + 60;
        $this->store->touch($session);
        self::assertSame('2027-01-15 08:01:00', $this->storedTimes($session->getId())['last_seen_at']);
        self::assertSame('2027-01-15 08:00:00', $this->storedTimes($session->getId())['created_at']);

        $this->clock->now = self::START + 60 + self::DAY - 1;
        self::assertNotNull($this->store->find($session->getId()));
    }

    public function testDeleteEndsTheSession(): void
    {
        $id = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18')->getId();

        $this->store->delete($id);
        $this->store->delete($id);

        self::assertNull($this->store->find($id));
        self::assertSame([], $this->storedTimes($id));
    }

    public function testDeleteExpiredRemovesOnlyExpiredSessionsOldestFirstInBatches(): void
    {
        $oldest = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18')->getId();
        $this->clock->now = self::START + 10;
        $older = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18')->getId();
        $this->clock->now = self::START + 20;
        $old = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18')->getId();
        $this->clock->now = self::START + self::DAY;
        $fresh = $this->store->create($this->admin(2), $this->client('b', '1'), '2025-06-18')->getId();

        $this->clock->now = self::START + 20 + self::DAY;
        self::assertSame(2, $this->store->deleteExpired(2));
        self::assertSame([], $this->storedTimes($oldest));
        self::assertSame([], $this->storedTimes($older));
        self::assertNotSame([], $this->storedTimes($old));

        self::assertSame(1, $this->store->deleteExpired(2));
        self::assertSame(0, $this->store->deleteExpired(2));
        self::assertSame([], $this->storedTimes($old));
        self::assertNotNull($this->store->find($fresh));
    }

    public function testABatchBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->deleteExpired(0);
    }

    public function testOpeningOneSessionMoreThanTheCapEndsTheCallersLeastRecentlySeen(): void
    {
        $store = $this->storeWithCap(3);
        $first = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');
        $this->clock->now = self::START + 100;
        $second = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');
        $this->clock->now = self::START + 200;
        $third = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');
        $this->clock->now = self::START + 300;
        $store->touch($first);
        $otherAdmins = $store->create($this->admin(2), $this->client('b', '1'), '2025-06-18');

        $this->clock->now = self::START + 400;
        $fourth = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');

        self::assertNull($store->find($second->getId()), 'The least recently seen session ended.');
        self::assertNotNull($store->find($first->getId()), 'A session seen lately is kept.');
        self::assertNotNull($store->find($third->getId()));
        self::assertNotNull($store->find($fourth->getId()));
        self::assertNotNull($store->find($otherAdmins->getId()), 'Another caller\'s sessions are not counted.');
    }

    public function testTheSessionJustOpenedIsKeptEvenWhenEveryOtherSessionWasSeenAtTheSameMoment(): void
    {
        $store = $this->storeWithCap(1);
        $earlier = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');

        $opened = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');

        self::assertNotNull($store->find($opened->getId()));
        self::assertNull($store->find($earlier->getId()));
    }

    public function testGuestSessionsAreCappedTogether(): void
    {
        $store = $this->storeWithCap(2);
        $guest = $this->callerFactory()->create(['userType' => UserContextInterface::USER_TYPE_GUEST]);
        $first = $store->create($guest, $this->client('', ''), '2025-06-18');
        $this->clock->now = self::START + 1;
        $second = $store->create($guest, $this->client('', ''), '2025-06-18');
        $admin = $store->create($this->admin(1), $this->client('a', '1'), '2025-06-18');
        $this->clock->now = self::START + 2;

        $third = $store->create($guest, $this->client('', ''), '2025-06-18');

        self::assertNull($store->find($first->getId()));
        self::assertNotNull($store->find($second->getId()));
        self::assertNotNull($store->find($third->getId()));
        self::assertNotNull($store->find($admin->getId()));
    }

    public function testTheContainersStoreKeepsTwentySessionsPerCaller(): void
    {
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $this->clock->now = self::START + $i;
            $ids[] = $this->store->create($this->admin(1), $this->client('a', '1'), '2025-06-18')->getId();
        }

        self::assertNull($this->store->find($ids[0]));
        self::assertSame(
            array_slice($ids, 1),
            array_values(array_filter($ids, fn (string $id): bool => $this->store->find($id) !== null))
        );
    }

    public function testACapBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storeWithCap(0);
    }

    /**
     * A store over the test's clock that keeps at most this many sessions per caller
     *
     * @param int $cap
     * @return DbSessionStore
     */
    private function storeWithCap(int $cap): DbSessionStore
    {
        $store = Bootstrap::getObjectManager()->create(
            DbSessionStore::class,
            ['clock' => $this->clock, 'maxSessionsPerCaller' => $cap]
        );
        self::assertInstanceOf(DbSessionStore::class, $store);

        return $store;
    }

    /**
     * The stored times of a session's row, or [] when there is no row
     *
     * @param string $id
     * @return array<string, mixed>
     */
    private function storedTimes(string $id): array
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName(DbSessionStore::TABLE), ['created_at', 'last_seen_at'])
                ->where('session_id = ?', $id)
        );

        if (!is_array($row)) {
            return [];
        }
        $times = [];
        foreach ($row as $column => $value) {
            $times[(string) $column] = $value;
        }

        return $times;
    }

    /**
     * @param int $id
     * @return CallerInterface
     */
    private function admin(int $id): CallerInterface
    {
        return $this->callerFactory()->create(['userType' => UserContextInterface::USER_TYPE_ADMIN, 'userId' => $id]);
    }

    /**
     * @param string $name
     * @param string $version
     * @return ClientInterface
     */
    private function client(string $name, string $version): ClientInterface
    {
        return $this->service(ClientInterfaceFactory::class)
            ->create(['name' => $name, 'version' => $version]);
    }

    /**
     * @return CallerInterfaceFactory
     */
    private function callerFactory(): CallerInterfaceFactory
    {
        return $this->service(CallerInterfaceFactory::class);
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
