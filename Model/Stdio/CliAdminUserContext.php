<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Magento\Authorization\Model\UserContextInterface;

/**
 * The admin a stdio message runs as, one of the user contexts Magento's composite user context asks in the Web API
 *
 * It answers nothing until the stdio command has set the admin, and never in a process that is not a command line,
 * so no web request can turn it on. It sorts before every other user context, so once set it decides who the caller
 * is. One instance is shared by the process.
 */
class CliAdminUserContext implements UserContextInterface
{
    /**
     * @var int|null
     */
    private ?int $adminId = null;

    /**
     * @param SapiName $sapiName
     */
    public function __construct(
        private readonly SapiName $sapiName
    ) {
    }

    /**
     * Run the rest of this process as the admin with this id
     *
     * @param int $adminId
     * @return void
     * @throws \InvalidArgumentException When the id is not positive
     */
    public function actAs(int $adminId): void
    {
        if ($adminId < 1) {
            throw new \InvalidArgumentException('An admin id is positive.');
        }

        $this->adminId = $adminId;
    }

    /**
     * The admin's id once set in a command line, else null
     *
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->answers() ? $this->adminId : null;
    }

    /**
     * The admin user type once set in a command line, else null
     *
     * @return int|null
     */
    public function getUserType(): ?int
    {
        return $this->answers() ? UserContextInterface::USER_TYPE_ADMIN : null;
    }

    /**
     * Whether the context speaks for the caller: an admin was set, in a command line
     *
     * @return bool
     */
    private function answers(): bool
    {
        return $this->adminId !== null && $this->sapiName->isCommandLine();
    }
}
