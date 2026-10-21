<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Guard;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\InvocationGuardInterface;

/**
 * Refuses a customer save that carries a password hash: passwords are never set through MCP
 *
 * The customer save ability takes a top-level parameter `passwordHash` that replaces the customer's password with
 * whatever hash the caller sends, which would let a client take over the account. The guard refuses a call of that
 * ability whose arguments hold the parameter with a value other than null or '', under either name Magento's Web API
 * input processor accepts for it: `passwordHash` or `password_hash`.
 *
 * The ability's name comes from the `ability` argument in di.xml, so the guard follows the name the source gives it.
 */
class CustomerPasswordHashGuard implements InvocationGuardInterface
{
    private const PARAMETER = 'passwordHash';

    /**
     * @param string $ability Name of the customer save ability
     */
    public function __construct(
        private readonly string $ability
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refusal(AbilityInterface $ability, array $arguments): ?string
    {
        if ($ability->getName() !== $this->ability) {
            return null;
        }

        foreach ($this->acceptedNames() as $name) {
            $value = $arguments[$name] ?? null;
            if ($value !== null && $value !== '') {
                return __(
                    'Passwords are never set through MCP: send the call without "%1".',
                    $name
                )->render();
            }
        }

        return null;
    }

    /**
     * The parameter's name as the method declares it and in snake case, the two forms the input processor reads
     *
     * @return list<string>
     */
    private function acceptedNames(): array
    {
        return [
            self::PARAMETER,
            strtolower((string) preg_replace('/(?<=\w)(?=[A-Z])/', '_', self::PARAMETER)),
        ];
    }
}
