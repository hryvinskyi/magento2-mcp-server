<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model;

use Hryvinskyi\McpServer\Api\McpEndpointInterface;
use Hryvinskyi\McpServer\Model\McpEndpointPlaceholder;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The service behind the MCP route, as the container resolves it
 */
class McpEndpointPlaceholderTest extends TestCase
{
    public function testRefusesToAnswerAnything(): void
    {
        $endpoint = Bootstrap::getObjectManager()->get(McpEndpointInterface::class);
        self::assertInstanceOf(McpEndpointPlaceholder::class, $endpoint);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('MCP messages are answered by the MCP endpoint');

        $endpoint->handle();
    }
}
