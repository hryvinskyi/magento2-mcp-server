<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Magento\Authorization\Model\UserContextInterface;

/**
 * Immutable caller, created through CallerInterfaceFactory
 *
 * The user type is one Magento knows. An integration, an admin or a customer always carries its positive id, and a
 * guest never carries one (Magento's guest context reports 0, which is kept as no id), so two callers are the same
 * account exactly when type and id are equal.
 */
class Caller implements CallerInterface
{
    private const USER_TYPES = [
        UserContextInterface::USER_TYPE_INTEGRATION,
        UserContextInterface::USER_TYPE_ADMIN,
        UserContextInterface::USER_TYPE_CUSTOMER,
        UserContextInterface::USER_TYPE_GUEST,
    ];

    /**
     * @var int|null
     */
    private readonly ?int $userId;

    /**
     * @param int $userType
     * @param int|null $userId
     * @throws \InvalidArgumentException When the type is unknown or the id does not suit the type
     */
    public function __construct(
        private readonly int $userType,
        ?int $userId = null
    ) {
        if (!in_array($userType, self::USER_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('The user type %d is unknown.', $userType));
        }

        if ($userType === UserContextInterface::USER_TYPE_GUEST) {
            if ($userId !== null && $userId !== 0) {
                throw new \InvalidArgumentException('A guest caller has no user id.');
            }
            $this->userId = null;

            return;
        }

        if ($userId === null || $userId < 1) {
            throw new \InvalidArgumentException(
                sprintf('A caller of user type %d needs a positive user id.', $userType)
            );
        }
        $this->userId = $userId;
    }

    /**
     * @inheritDoc
     */
    public function getUserType(): int
    {
        return $this->userType;
    }

    /**
     * @inheritDoc
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }
}
