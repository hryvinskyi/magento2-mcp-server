<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Data;

use Hryvinskyi\McpServer\Model\Data\Client;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testKeepsNameAndVersion(): void
    {
        $client = new Client('desk-assistant', '0.1.0');

        self::assertSame('desk-assistant', $client->getName());
        self::assertSame('0.1.0', $client->getVersion());
    }

    public function testAClientNeedNotIntroduceItself(): void
    {
        $client = new Client();

        self::assertSame('', $client->getName());
        self::assertSame('', $client->getVersion());
    }

    public function testTheLongestNameAndVersionAreKept(): void
    {
        $client = new Client(str_repeat('ж', 255), str_repeat('9', 64));

        self::assertSame(255, mb_strlen($client->getName()));
        self::assertSame(64, mb_strlen($client->getVersion()));
    }

    public function testRefusesALongerName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(str_repeat('a', 256));
    }

    public function testRefusesALongerVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client('client', str_repeat('1', 65));
    }
}
