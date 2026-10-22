<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Client;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which URLs the server may call as its GraphQL endpoint
 */
class EndpointUrlValidatorTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testAcceptsAnAbsoluteHttpUrlEndingInGraphql(string $url): void
    {
        self::assertTrue((new EndpointUrlValidator())->isValid($url));
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingElse(string $url): void
    {
        self::assertFalse((new EndpointUrlValidator())->isValid($url));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function accepted(): array
    {
        return [
            'https' => ['https://shop.example.com/graphql'],
            'http' => ['http://shop.example.com/graphql'],
            'upper-case scheme and host' => ['HTTPS://Shop.Example.COM/graphql'],
            'port' => ['http://web:8080/graphql'],
            'container host with underscore' => ['http://magento_web_1/graphql'],
            'sub-path' => ['https://example.com/shop/graphql'],
            'ipv4' => ['http://127.0.0.1/graphql'],
            'ipv6' => ['http://[::1]:8080/graphql'],
        ];
    }

    /**
     * @return array<string,array{string}>
     */
    public static function refused(): array
    {
        return [
            'empty' => [''],
            'relative' => ['/graphql'],
            'no scheme' => ['shop.example.com/graphql'],
            'ftp' => ['ftp://shop.example.com/graphql'],
            'file' => ['file:///var/www/graphql'],
            'gopher' => ['gopher://shop.example.com/graphql'],
            'other path' => ['https://shop.example.com/rest/V1/graphql/x'],
            'trailing slash' => ['https://shop.example.com/graphql/'],
            'a longer last segment' => ['https://shop.example.com/graphqlx'],
            'user info' => ['https://user:secret@shop.example.com/graphql'],
            'query string' => ['https://shop.example.com/graphql?a=1'],
            'fragment' => ['https://shop.example.com/graphql#x'],
            'whitespace' => ['https://shop.example.com/a b/graphql'],
            'line break' => ["https://shop.example.com/graphql\n"],
            'header smuggling' => ["https://shop.example.com/x\r\nX-Evil: 1/graphql"],
            'no host' => ['https:///graphql'],
        ];
    }
}
