<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Data;

use Hryvinskyi\McpServer\Model\Data\Caller;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CallerTest extends TestCase
{
    public function testAnAdminCarriesItsId(): void
    {
        $caller = new Caller(UserContextInterface::USER_TYPE_ADMIN, 7);

        self::assertSame(UserContextInterface::USER_TYPE_ADMIN, $caller->getUserType());
        self::assertSame(7, $caller->getUserId());
    }

    public function testAGuestHasNoId(): void
    {
        self::assertNull((new Caller(UserContextInterface::USER_TYPE_GUEST))->getUserId());
    }

    public function testTheGuestContextsZeroIdIsNoId(): void
    {
        self::assertNull((new Caller(UserContextInterface::USER_TYPE_GUEST, 0))->getUserId());
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function brokenCallers(): array
    {
        return [
            'unknown type' => [9, 1],
            'zero type' => [0, 1],
            'admin without id' => [UserContextInterface::USER_TYPE_ADMIN, null],
            'admin with id zero' => [UserContextInterface::USER_TYPE_ADMIN, 0],
            'integration with negative id' => [UserContextInterface::USER_TYPE_INTEGRATION, -3],
            'customer without id' => [UserContextInterface::USER_TYPE_CUSTOMER, null],
            'guest with an id' => [UserContextInterface::USER_TYPE_GUEST, 5],
        ];
    }

    #[DataProvider('brokenCallers')]
    public function testRefusesAnIdThatDoesNotSuitTheType(int $userType, ?int $userId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Caller($userType, $userId);
    }
}
