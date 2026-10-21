<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

use Magento\Authorization\Model\UserContextInterface;

/**
 * The caller a test says it is
 */
class FixedUserContext implements UserContextInterface
{
    /**
     * @param int|null $userId
     * @param int|null $userType
     */
    public function __construct(
        private readonly ?int $userId,
        private readonly ?int $userType = UserContextInterface::USER_TYPE_ADMIN
    ) {
    }

    /**
     * @return int|null
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @return int|null
     */
    public function getUserType()
    {
        return $this->userType;
    }
}
