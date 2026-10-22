<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Stdio\AdminAccount;
use Hryvinskyi\McpServer\Model\Stdio\AdminAccountFactory;
use Hryvinskyi\McpServer\Model\Stdio\AdminUserAccounts;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedWallClock;
use Magento\Security\Model\UserExpirationManager;
use Magento\User\Model\ResourceModel\User as UserResource;
use PHPUnit\Framework\TestCase;

/**
 * What the admin account adapter makes of an admin user row: active from its flag, locked while its lock expiry is
 * still ahead on the wall clock, expired when Magento's expiry check says so for its id; the database reads
 * themselves are covered by the integration test
 */
class AdminUserAccountsTest extends TestCase
{
    private const NOW = 1790000000;

    /**
     * The user ids Magento's expiry check was asked about, in order
     *
     * @var list<string>
     */
    private array $expiryChecks = [];

    public function testAnUnknownLoginHasNoAccount(): void
    {
        self::assertNull($this->accounts(false)->byLogin('nobody'));
    }

    public function testAnActiveUnlockedAccount(): void
    {
        $account = $this->accounts(['user_id' => '5', 'is_active' => '1', 'lock_expires' => null])->byLogin('jane');

        self::assertNotNull($account);
        self::assertSame(5, $account->getId());
        self::assertTrue($account->isActive());
        self::assertFalse($account->isLocked());
        self::assertFalse($account->isExpired());
        self::assertSame(['5'], $this->expiryChecks);
    }

    public function testAnAccountPastItsExpiryDateIsExpired(): void
    {
        $account = $this->accounts(['user_id' => '5', 'is_active' => '1', 'lock_expires' => null], ['5'])
            ->byLogin('jane');

        self::assertNotNull($account);
        self::assertTrue($account->isExpired());
        self::assertTrue($account->isActive(), 'Magento deactivates an expired account only at its next sign-in.');
    }

    public function testAnUnknownLoginIsNeverCheckedForExpiry(): void
    {
        $this->accounts(false, ['5'])->byLogin('nobody');

        self::assertSame([], $this->expiryChecks);
    }

    public function testAnInactiveAccount(): void
    {
        $account = $this->accounts(['user_id' => '5', 'is_active' => '0', 'lock_expires' => null])->byLogin('jane');

        self::assertNotNull($account);
        self::assertFalse($account->isActive());
    }

    public function testALockThatExpiresLaterLocksTheAccountAndOneThatExpiredDoesNot(): void
    {
        $later = gmdate('Y-m-d H:i:s', self::NOW + 1);
        $earlier = gmdate('Y-m-d H:i:s', self::NOW - 1);

        $locked = $this->accounts(['user_id' => '5', 'is_active' => '1', 'lock_expires' => $later])->byLogin('jane');
        $unlocked = $this->accounts(['user_id' => '5', 'is_active' => '1', 'lock_expires' => $earlier])
            ->byLogin('jane');

        self::assertNotNull($locked);
        self::assertTrue($locked->isLocked());
        self::assertNotNull($unlocked);
        self::assertFalse($unlocked->isLocked());
    }

    /**
     * @param array<string, string|null>|false $row What the admin user table holds for the login
     * @param list<string> $expiredIds The user ids whose expiry date has passed
     * @return AdminUserAccounts
     */
    private function accounts(array|false $row, array $expiredIds = []): AdminUserAccounts
    {
        $users = $this->createStub(UserResource::class);
        $users->method('loadByUsername')->willReturn($row);
        $expiration = $this->createStub(UserExpirationManager::class);
        $expiration->method('isUserExpired')->willReturnCallback(
            function (string $userId) use ($expiredIds): bool {
                $this->expiryChecks[] = $userId;

                return in_array($userId, $expiredIds, true);
            }
        );

        return new AdminUserAccounts(
            $users,
            new AdminAccountFactory(new ConstructingObjectManager([AdminAccount::class => AdminAccount::class])),
            new FixedWallClock(self::NOW),
            $expiration
        );
    }
}
