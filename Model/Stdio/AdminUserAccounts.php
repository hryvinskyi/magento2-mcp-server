<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Hryvinskyi\McpServer\Model\Clock\WallClockInterface;
use Magento\Security\Model\UserExpirationManager;
use Magento\User\Model\ResourceModel\User as UserResource;

/**
 * Admin accounts read from Magento's admin user table
 *
 * - An account is locked while its lock expiry lies in the future on the wall clock. The table keeps that expiry in
 *   UTC as `Y-m-d H:i:s`, the format the clock's time is written in for the comparison, so the two compare as text.
 * - An account is expired when Magento's own expiry check says so. Magento runs that check when an admin signs in to
 *   the admin and deactivates the account then; the stdio transport never signs in, so it asks the same check.
 */
class AdminUserAccounts implements AdminAccountsInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param UserResource $users
     * @param AdminAccountFactory $accountFactory
     * @param WallClockInterface $clock
     * @param UserExpirationManager $userExpiration
     */
    public function __construct(
        private readonly UserResource $users,
        private readonly AdminAccountFactory $accountFactory,
        private readonly WallClockInterface $clock,
        private readonly UserExpirationManager $userExpiration
    ) {
    }

    /**
     * @inheritDoc
     */
    public function byLogin(string $login): ?AdminAccount
    {
        $row = $this->users->loadByUsername($login);
        if (!is_numeric($row['user_id'] ?? null)) {
            return null;
        }

        $id = (int) $row['user_id'];
        $lockExpires = $row['lock_expires'] ?? null;

        return $this->accountFactory->create([
            'id' => $id,
            'active' => is_numeric($row['is_active'] ?? null) && (int) $row['is_active'] === 1,
            'locked' => is_string($lockExpires) && $lockExpires > gmdate(self::DATE_FORMAT, $this->clock->now()),
            'expired' => $this->userExpiration->isUserExpired((string) $id),
        ]);
    }
}
