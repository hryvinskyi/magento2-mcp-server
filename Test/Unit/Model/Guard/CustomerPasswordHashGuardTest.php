<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Guard;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Guard\CustomerPasswordHashGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The customer save never carries a password hash: under either name the Web API reads, for the configured ability
 * only
 */
class CustomerPasswordHashGuardTest extends TestCase
{
    private const SAVE = 'customer.customer_repository.save';

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function hashes(): array
    {
        return [
            'camel case' => [['customer' => ['email' => 'a@example.com'], 'passwordHash' => 'x']],
            'snake case' => [['customer' => ['email' => 'a@example.com'], 'password_hash' => 'x']],
            'blank but not empty' => [['passwordHash' => ' ']],
            'not a string' => [['passwordHash' => ['hash' => 'x']]],
            'empty under one name, set under the other' => [['passwordHash' => '', 'password_hash' => 'x']],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('hashes')]
    public function testASaveThatCarriesAPasswordHashIsRefused(array $arguments): void
    {
        $refusal = $this->guard()->refusal($this->ability(self::SAVE), $arguments);

        self::assertIsString($refusal);
        self::assertStringStartsWith('Passwords are never set through MCP', $refusal);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function withoutHash(): array
    {
        return [
            'no hash' => [['customer' => ['email' => 'a@example.com']]],
            'null' => [['passwordHash' => null]],
            'empty' => [['passwordHash' => '', 'password_hash' => '']],
            'inside the customer, which the method does not read' => [['customer' => ['passwordHash' => 'x']]],
            'another case the Web API does not read' => [['PASSWORDHASH' => 'x']],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('withoutHash')]
    public function testASaveWithoutAPasswordHashGoesThrough(array $arguments): void
    {
        self::assertNull($this->guard()->refusal($this->ability(self::SAVE), $arguments));
    }

    public function testAnotherAbilityIsNeverRefused(): void
    {
        self::assertNull(
            $this->guard()->refusal($this->ability('customer.customer_repository.get_by_id'), ['passwordHash' => 'x'])
        );
    }

    public function testTheGuardedAbilityIsTheOneConfigured(): void
    {
        $guard = new CustomerPasswordHashGuard('acme_shop.customer_repository.save');

        self::assertNull($guard->refusal($this->ability(self::SAVE), ['passwordHash' => 'x']));
        self::assertNotNull(
            $guard->refusal($this->ability('acme_shop.customer_repository.save'), ['passwordHash' => 'x'])
        );
    }

    /**
     * @return CustomerPasswordHashGuard
     */
    private function guard(): CustomerPasswordHashGuard
    {
        return new CustomerPasswordHashGuard(self::SAVE);
    }

    /**
     * @param string $name
     * @return Ability
     */
    private function ability(string $name): Ability
    {
        return new Ability(
            $name,
            'Save a customer',
            'Magento_Customer',
            'webapi',
            'PUT /V1/customers/:customerId',
            AbilityAccess::Write,
            ['Magento_Customer::manage']
        );
    }
}
