<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

/**
 * An admin account as the stdio transport needs to know it, created through AdminAccountFactory
 */
class AdminAccount
{
    /**
     * @param int $id
     * @param bool $active Whether the account is active
     * @param bool $locked Whether the account is locked at the time it was read
     * @param bool $expired Whether the account's expiry date had passed when it was read
     * @throws \InvalidArgumentException When the id is not positive
     */
    public function __construct(
        private readonly int $id,
        private readonly bool $active,
        private readonly bool $locked,
        private readonly bool $expired = false
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('An admin account id is positive.');
        }
    }

    /**
     * The account's id
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Whether the account is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Whether the account was locked when it was read, after too many failed sign-ins or by an administrator
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->locked;
    }

    /**
     * Whether the account's expiry date had passed when it was read
     *
     * An administrator may give an admin account an expiry date. Magento deactivates such an account when it next
     * signs in to the admin, or when its cron job runs, so until then it may still be marked active.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expired;
    }
}
