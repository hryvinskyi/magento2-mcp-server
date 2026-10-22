<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

/**
 * The installation's admin accounts, found by login
 */
interface AdminAccountsInterface
{
    /**
     * The account with this login, read now, or null when there is none
     *
     * @param string $login
     * @return AdminAccount|null
     */
    public function byLogin(string $login): ?AdminAccount;
}
