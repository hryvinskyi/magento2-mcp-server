<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Data;

use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Data\Invocation;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvocationTest extends TestCase
{
    public function testKeepsWhatItWasGiven(): void
    {
        $caller = new Caller(UserContextInterface::USER_TYPE_ADMIN, 3);
        $client = new Client('desk-assistant', '0.1.0');
        $invocation = new Invocation(
            'catalog.product_repository.get',
            $caller,
            $client,
            'http',
            'b1946ac92492d2347c6235b4d2611184',
            true
        );

        self::assertSame('catalog.product_repository.get', $invocation->getAbilityName());
        self::assertSame($caller, $invocation->getCaller());
        self::assertSame($client, $invocation->getClient());
        self::assertSame('http', $invocation->getTransport());
        self::assertSame('b1946ac92492d2347c6235b4d2611184', $invocation->getSessionId());
        self::assertTrue($invocation->isConfirmed());
        self::assertSame([], $invocation->getArguments());
    }

    public function testAnUnconfirmedCallOutsideASessionIsTheDefault(): void
    {
        $invocation = $this->invocation('stdio');

        self::assertFalse($invocation->isConfirmed());
        self::assertSame('', $invocation->getSessionId());
    }

    public function testKeepsANameThatNamesNoAbility(): void
    {
        $invocation = new Invocation(
            'Not An Ability',
            new Caller(UserContextInterface::USER_TYPE_ADMIN, 1),
            new Client(),
            'http'
        );

        self::assertSame('Not An Ability', $invocation->getAbilityName());
    }

    public function testArgumentsGoToACopy(): void
    {
        $invocation = $this->invocation('http');
        $arguments = ['sku' => '24-MB01', 'nested' => ['instance' => \stdClass::class, 'argument' => 'db']];

        $withArguments = $invocation->withArguments($arguments);

        self::assertNotSame($invocation, $withArguments);
        self::assertSame([], $invocation->getArguments());
        self::assertSame($arguments, $withArguments->getArguments());
        self::assertSame($invocation->getCaller(), $withArguments->getCaller());
        self::assertSame('http', $withArguments->getTransport());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenTransports(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['HTTP'],
            'leading digit' => ['2http'],
            'space' => ['std io'],
            'trailing newline' => ["http\n"],
        ];
    }

    #[DataProvider('brokenTransports')]
    public function testRefusesATransportThatIsNotACode(string $transport): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->invocation($transport);
    }

    /**
     * @param string $transport
     * @return Invocation
     */
    private function invocation(string $transport): Invocation
    {
        return new Invocation(
            'directory.country_information_acquirer.get_countries_info',
            new Caller(UserContextInterface::USER_TYPE_INTEGRATION, 4),
            new Client(),
            $transport
        );
    }
}
