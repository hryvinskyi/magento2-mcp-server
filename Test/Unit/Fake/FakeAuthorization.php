<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\AuthorizationInterface;

/**
 * An ACL that allows a fixed set of resources and records every resource it was asked about
 */
class FakeAuthorization implements AuthorizationInterface
{
    /**
     * Every resource asked about, in order
     *
     * @var list<string>
     */
    public array $asked = [];

    /**
     * @param list<string> $allowed
     */
    public function __construct(
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
        $this->asked[] = $resource;

        return in_array($resource, $this->allowed, true);
    }
}
