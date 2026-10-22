<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Stdio\AdminAccount;
use Hryvinskyi\McpServer\Model\Stdio\AdminAccountsInterface;

/**
 * Admin accounts a test chooses, by login
 */
class FakeAdminAccounts implements AdminAccountsInterface
{
    /**
     * @param array<string, AdminAccount> $accounts
     */
    public function __construct(
        private readonly array $accounts
    ) {
    }

    /**
     * @inheritDoc
     */
    public function byLogin(string $login): ?AdminAccount
    {
        return $this->accounts[$login] ?? null;
    }
}
