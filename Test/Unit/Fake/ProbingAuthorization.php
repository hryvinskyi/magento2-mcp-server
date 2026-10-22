<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\AuthorizationInterface;

/**
 * An ACL that allows a fixed set of resources and records, for every question, who the user context said the caller
 * was at that moment
 */
class ProbingAuthorization implements AuthorizationInterface
{
    /**
     * Every question, in order, with the user type and id the context answered when it was asked
     *
     * @var list<array{resource: string, userType: int|null, userId: int|null}>
     */
    public array $asked = [];

    /**
     * @param UserContextInterface $userContext
     * @param list<string> $allowed
     */
    public function __construct(
        private readonly UserContextInterface $userContext,
        private readonly array $allowed
    ) {
    }

    /**
     * @param string $resource
     * @param string|null $privilege
     * @return bool
     */
    public function isAllowed($resource, $privilege = null)
    {
        $type = $this->userContext->getUserType();
        $id = $this->userContext->getUserId();
        $this->asked[] = [
            'resource' => $resource,
            'userType' => is_numeric($type) ? (int) $type : null,
            'userId' => is_numeric($id) ? (int) $id : null,
        ];

        return in_array($resource, $this->allowed, true);
    }
}
