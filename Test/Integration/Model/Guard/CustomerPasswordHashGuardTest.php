<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Guard;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\Guard\CustomerPasswordHashGuard;
use Hryvinskyi\McpServer\Model\Source\Webapi\AbilityNamer;
use Hryvinskyi\McpServer\Test\Integration\Fixture\RecordingPoolAbility;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The password-hash guard as the container builds it guards the customer save ability of the Web API source, by the
 * name that source gives it, and no other ability
 *
 * The customer save's name is the one the container gives the guard, and it is the name the Web API source's namer
 * gives that service contract's save: if the naming moved, the guard would match nothing and a save carrying a password
 * hash would go through. The namer works on the contract's name alone, so this holds on a store without the customer
 * module; nothing here calls that ability, which is only described.
 */
class CustomerPasswordHashGuardTest extends TestCase
{
    private const CUSTOMER_SAVE = 'customer.customer_repository.save';

    /**
     * The service contract whose save the guard protects, by name only: the namer reads the name, it loads no class
     */
    private const CUSTOMER_REPOSITORY = 'Magento\\Customer\\Api\\CustomerRepositoryInterface';

    public function testTheContainersGuardRefusesTheCustomerSaveCarryingAPasswordHash(): void
    {
        $refusal = $this->guard()->refusal(
            $this->ability(self::CUSTOMER_SAVE),
            ['customer' => ['email' => 'mcp-guard@example.com'], 'passwordHash' => 'a hash chosen by the client']
        );

        self::assertIsString($refusal);
        self::assertStringStartsWith('Passwords are never set through MCP', $refusal);
    }

    public function testTheGuardedNameIsTheOneTheWebApiSourceGivesTheCustomerSave(): void
    {
        $namer = Bootstrap::getObjectManager()->get(AbilityNamer::class);
        self::assertInstanceOf(AbilityNamer::class, $namer);

        self::assertSame(self::CUSTOMER_SAVE, $namer->nameOf(self::CUSTOMER_REPOSITORY, 'save'));
    }

    public function testTheContainersGuardLetsAnotherSaveThrough(): void
    {
        self::assertNull(
            $this->guard()->refusal(
                $this->ability(RecordingPoolAbility::SAVE),
                ['passwordHash' => 'a hash chosen by the client']
            )
        );
    }

    /**
     * The guard the invoker's guard pool resolves, built from the container's configuration
     *
     * @return CustomerPasswordHashGuard
     */
    private function guard(): CustomerPasswordHashGuard
    {
        $guard = Bootstrap::getObjectManager()->create(CustomerPasswordHashGuard::class);
        self::assertInstanceOf(CustomerPasswordHashGuard::class, $guard);

        return $guard;
    }

    /**
     * A write ability by this name
     *
     * @param string $name
     * @return AbilityInterface
     */
    private function ability(string $name): AbilityInterface
    {
        $factory = Bootstrap::getObjectManager()->get(AbilityInterfaceFactory::class);
        self::assertInstanceOf(AbilityInterfaceFactory::class, $factory);

        return $factory->create([
            'name' => $name,
            'title' => 'Save a record',
            'category' => 'Hryvinskyi_McpServer',
            'source' => 'webapi',
            'sourceReference' => 'test',
            'access' => AbilityAccess::Write,
            'aclResources' => ['Hryvinskyi_McpServer::access'],
        ]);
    }
}
