<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * The account an invocation runs as: the one whose ACL is evaluated
 *
 * Create one through CallerInterfaceFactory.
 *
 * @api
 */
interface CallerInterface
{
    /**
     * Kind of account, one of the Magento\Authorization\Model\UserContextInterface::USER_TYPE_* constants
     *
     * @return int
     */
    public function getUserType(): int;

    /**
     * Account id within its kind; null only for a guest
     *
     * @return int|null
     */
    public function getUserId(): ?int;
}
