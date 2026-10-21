<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\ProtocolVersions;
use PHPUnit\Framework\TestCase;

/**
 * Version negotiation: the client's version when the server speaks it, else the newest
 */
class ProtocolVersionsTest extends TestCase
{
    public function testTheRequestedVersionIsKeptWhenSupportedElseTheNewestIsAnswered(): void
    {
        $versions = new ProtocolVersions(['new' => '2025-06-18', 'old' => '2024-11-05']);

        self::assertSame('2024-11-05', $versions->negotiate('2024-11-05'));
        self::assertSame('2025-06-18', $versions->negotiate('2023-01-01'));
        self::assertSame('2025-06-18', $versions->negotiate(''));
        self::assertTrue($versions->supports('2024-11-05'));
        self::assertFalse($versions->supports('2025-03-26'));
    }

    public function testAVersionSetToNullIsDropped(): void
    {
        $versions = new ProtocolVersions(['new' => null, 'old' => '2024-11-05']);

        self::assertSame(['2024-11-05'], $versions->all());
        self::assertSame('2024-11-05', $versions->negotiate('2025-06-18'));
    }

    public function testAServerWithoutAVersionCannotBeBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProtocolVersions(['dropped' => null]);
    }
}
