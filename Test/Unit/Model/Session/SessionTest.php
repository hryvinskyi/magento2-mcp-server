<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Session;

use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Session\Session;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A session's validation, its owner, its expiry and how often its use is recorded
 */
class SessionTest extends TestCase
{
    private const ID = '0123456789abcdef0123456789abcdef';
    private const CREATED = 1_800_000_000;

    public function testASessionKeepsWhatItWasOpenedWith(): void
    {
        $caller = new Caller(UserContextInterface::USER_TYPE_INTEGRATION, 4);
        $client = new Client('Other Client', '1.0');

        $session = new Session(self::ID, $caller, $client, '2025-06-18', self::CREATED, self::CREATED + 5);

        self::assertSame(self::ID, $session->getId());
        self::assertSame($caller, $session->getCaller());
        self::assertSame($client, $session->getClient());
        self::assertSame('2025-06-18', $session->getProtocolVersion());
        self::assertSame(self::CREATED, $session->getCreatedAt());
        self::assertSame(self::CREATED + 5, $session->getLastSeenAt());
    }

    /**
     * @return array<string, array{string, string, int, int}>
     */
    public static function invalidSessions(): array
    {
        return [
            'short id' => ['abc', '2025-06-18', self::CREATED, self::CREATED],
            'uppercase id' => [strtoupper(self::ID), '2025-06-18', self::CREATED, self::CREATED],
            'id with a newline' => [self::ID . "\n", '2025-06-18', self::CREATED, self::CREATED],
            'no version' => [self::ID, '', self::CREATED, self::CREATED],
            'overlong version' => [self::ID, str_repeat('9', 17), self::CREATED, self::CREATED],
            'seen before created' => [self::ID, '2025-06-18', self::CREATED, self::CREATED - 1],
            'negative time' => [self::ID, '2025-06-18', -1, 0],
        ];
    }

    #[DataProvider('invalidSessions')]
    public function testAnInvalidSessionCannotBeBuilt(string $id, string $version, int $created, int $seen): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Session($id, $this->admin(1), new Client(), $version, $created, $seen);
    }

    public function testASessionBelongsOnlyToTheSameUserTypeAndId(): void
    {
        $session = $this->session(self::CREATED);

        self::assertTrue($session->belongsTo($this->admin(1)));
        self::assertFalse($session->belongsTo($this->admin(2)));
        self::assertFalse($session->belongsTo(new Caller(UserContextInterface::USER_TYPE_INTEGRATION, 1)));
        self::assertFalse($session->belongsTo(new Caller(UserContextInterface::USER_TYPE_CUSTOMER, 1)));
    }

    public function testASessionExpiresADayAfterItWasLastSeen(): void
    {
        $session = $this->session(self::CREATED);

        self::assertFalse($session->isExpiredAt(self::CREATED + 86399));
        self::assertTrue($session->isExpiredAt(self::CREATED + 86400));
    }

    public function testUseIsRecordedOnlyOnceAMinute(): void
    {
        $session = $this->session(self::CREATED);

        self::assertFalse($session->needsTouchAt(self::CREATED));
        self::assertFalse($session->needsTouchAt(self::CREATED + 59));
        self::assertTrue($session->needsTouchAt(self::CREATED + 60));
    }

    /**
     * @param int $lastSeenAt
     * @return Session
     */
    private function session(int $lastSeenAt): Session
    {
        return new Session(self::ID, $this->admin(1), new Client(), '2025-06-18', self::CREATED, $lastSeenAt);
    }

    /**
     * @param int $id
     * @return Caller
     */
    private function admin(int $id): Caller
    {
        return new Caller(UserContextInterface::USER_TYPE_ADMIN, $id);
    }
}
