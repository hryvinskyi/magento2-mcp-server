<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Confirmation;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Model\Confirmation\Confirmation;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules a confirmation token follows: it confirms only the exact call it was handed out for, before it expires
 */
class ConfirmationTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';
    private const SESSION = 'abababababababababababababababab';
    private const ABILITY = 'catalog.product_repository.save';
    private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EXPIRES_AT = 2000;

    public function testItConfirmsTheCallItWasHandedOutForUntilItExpires(): void
    {
        $confirmation = $this->confirmation();

        self::assertTrue($confirmation->confirms(self::SESSION, $this->admin(1), self::ABILITY, self::FINGERPRINT, 0));
        self::assertTrue(
            $confirmation->confirms(self::SESSION, $this->admin(1), self::ABILITY, self::FINGERPRINT, 1999)
        );
        self::assertFalse(
            $confirmation->confirms(self::SESSION, $this->admin(1), self::ABILITY, self::FINGERPRINT, 2000),
            'A token is expired from its expiry on.'
        );
    }

    /**
     * @return array<string, array{string, CallerInterface, string, string}>
     */
    public static function otherCalls(): array
    {
        $admin = new Caller(UserContextInterface::USER_TYPE_ADMIN, 1);

        return [
            'another session' => [str_repeat('cd', 16), $admin, self::ABILITY, self::FINGERPRINT],
            'no session' => ['', $admin, self::ABILITY, self::FINGERPRINT],
            'another admin' => [
                self::SESSION,
                new Caller(UserContextInterface::USER_TYPE_ADMIN, 2),
                self::ABILITY,
                self::FINGERPRINT,
            ],
            'another user type with the same id' => [
                self::SESSION,
                new Caller(UserContextInterface::USER_TYPE_INTEGRATION, 1),
                self::ABILITY,
                self::FINGERPRINT,
            ],
            'a guest' => [
                self::SESSION,
                new Caller(UserContextInterface::USER_TYPE_GUEST),
                self::ABILITY,
                self::FINGERPRINT,
            ],
            'another ability' => [self::SESSION, $admin, 'catalog.product_repository.delete', self::FINGERPRINT],
            'other arguments' => [self::SESSION, $admin, self::ABILITY, str_repeat('b', 64)],
        ];
    }

    #[DataProvider('otherCalls')]
    public function testItConfirmsNoOtherCall(
        string $sessionId,
        CallerInterface $caller,
        string $ability,
        string $fingerprint
    ): void {
        self::assertFalse($this->confirmation()->confirms($sessionId, $caller, $ability, $fingerprint, 0));
    }

    public function testATokenHandedOutOutsideASessionConfirmsOnlyCallsOutsideASession(): void
    {
        $confirmation = new Confirmation(self::TOKEN, '', $this->admin(1), self::ABILITY, self::FINGERPRINT, 10);

        self::assertTrue($confirmation->confirms('', $this->admin(1), self::ABILITY, self::FINGERPRINT, 0));
        self::assertFalse($confirmation->confirms(self::SESSION, $this->admin(1), self::ABILITY, self::FINGERPRINT, 0));
    }

    public function testAGuestTokenConfirmsOnlyAGuest(): void
    {
        $guest = new Caller(UserContextInterface::USER_TYPE_GUEST);
        $confirmation = new Confirmation(self::TOKEN, self::SESSION, $guest, self::ABILITY, self::FINGERPRINT, 10);

        self::assertTrue($confirmation->confirms(self::SESSION, $guest, self::ABILITY, self::FINGERPRINT, 0));
        self::assertFalse(
            $confirmation->confirms(
                self::SESSION,
                new Caller(UserContextInterface::USER_TYPE_CUSTOMER, 1),
                self::ABILITY,
                self::FINGERPRINT,
                0
            )
        );
    }

    public function testItKeepsWhatItWasBuiltWith(): void
    {
        $confirmation = $this->confirmation();

        self::assertSame(self::TOKEN, $confirmation->getToken());
        self::assertSame(self::SESSION, $confirmation->getSessionId());
        self::assertSame(1, $confirmation->getCaller()->getUserId());
        self::assertSame(self::ABILITY, $confirmation->getAbility());
        self::assertSame(self::FINGERPRINT, $confirmation->getArgumentsFingerprint());
        self::assertSame(self::EXPIRES_AT, $confirmation->getExpiresAt());
        self::assertSame(600, Confirmation::LIFETIME_SECONDS);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidParts(): array
    {
        return [
            'a short token' => ['0123', self::ABILITY, self::FINGERPRINT],
            'an uppercase token' => [strtoupper(self::TOKEN), self::ABILITY, self::FINGERPRINT],
            'a token with a newline' => [self::TOKEN . "\n", self::ABILITY, self::FINGERPRINT],
            'no ability' => [self::TOKEN, ' ', self::FINGERPRINT],
            'a short fingerprint' => [self::TOKEN, self::ABILITY, 'abc'],
            'a fingerprint that is not hexadecimal' => [self::TOKEN, self::ABILITY, str_repeat('z', 64)],
        ];
    }

    #[DataProvider('invalidParts')]
    public function testAnInvalidTokenCannotBeBuilt(string $token, string $ability, string $fingerprint): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Confirmation($token, self::SESSION, $this->admin(1), $ability, $fingerprint, self::EXPIRES_AT);
    }

    /**
     * @return Confirmation
     */
    private function confirmation(): Confirmation
    {
        return new Confirmation(
            self::TOKEN,
            self::SESSION,
            $this->admin(1),
            self::ABILITY,
            self::FINGERPRINT,
            self::EXPIRES_AT
        );
    }

    /**
     * @param int $id
     * @return CallerInterface
     */
    private function admin(int $id): CallerInterface
    {
        return new Caller(UserContextInterface::USER_TYPE_ADMIN, $id);
    }
}
