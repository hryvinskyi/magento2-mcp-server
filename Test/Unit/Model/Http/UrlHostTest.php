<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Http;

use Hryvinskyi\McpServer\Model\Http\UrlHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The host an absolute URL names: lower-cased, without user info, port, path, query or fragment
 */
class UrlHostTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function urlsWithAHost(): array
    {
        return [
            'a plain host' => ['https://shop.example', 'shop.example'],
            'a host followed by a path' => ['https://shop.example/rest/all/V1/mcp', 'shop.example'],
            'the port is dropped' => ['http://shop.example:8443/rest', 'shop.example'],
            'an empty port is dropped' => ['http://shop.example:/', 'shop.example'],
            'upper case is lowered' => ['HTTPS://Shop.Example.COM/', 'shop.example.com'],
            'user info is skipped' => ['https://user:secret@admin-host.example/', 'admin-host.example'],
            'a bracketed IPv6 address' => ['http://[::1]:8080/', '[::1]'],
            'an upper-case IPv6 address' => ['http://[FE80::1]/', '[fe80::1]'],
            'a query right after the host' => ['https://shop.example?a=b', 'shop.example'],
            'a fragment right after the host' => ['https://shop.example#top', 'shop.example'],
            'surrounding whitespace' => ["  https://shop.example/ \n", 'shop.example'],
            'any scheme' => ['ws+custom.1-a://shop.example', 'shop.example'],
        ];
    }

    #[DataProvider('urlsWithAHost')]
    public function testTheHostOfAnAbsoluteUrl(string $url, string $host): void
    {
        self::assertSame($host, (new UrlHost())->of($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function urlsWithoutAHost(): array
    {
        return [
            'the empty string' => [''],
            'no scheme' => ['shop.example'],
            'a path alone' => ['/rest/all/V1/mcp'],
            'a scheme-relative URL' => ['//shop.example/'],
            'a file URL with an empty host' => ['file:///etc/passwd'],
            'the opaque origin of a sandboxed page' => ['null'],
            'a scheme that starts with a digit' => ['1http://shop.example'],
            'a port that is not a number' => ['http://shop.example:port/'],
            'an unclosed IPv6 bracket' => ['http://[::1/'],
            'whitespace inside the host' => ['http://shop example/'],
        ];
    }

    #[DataProvider('urlsWithoutAHost')]
    public function testAUrlWithoutAHostHasNone(string $url): void
    {
        self::assertNull((new UrlHost())->of($url));
    }
}
