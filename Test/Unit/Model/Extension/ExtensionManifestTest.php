<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Extension;

use Hryvinskyi\McpServer\Model\Extension\ExtensionManifest;
use Hryvinskyi\McpServer\Model\Http\UrlHost;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Claude Desktop extension's manifest: named after the endpoint's host, the endpoint a setting with the store's
 * URL as default, the token a required secret it holds no value for
 */
class ExtensionManifestTest extends TestCase
{
    private const ENDPOINT = 'https://Shop.Example.com:8443/rest/all/V1/mcp';

    public function testTheManifestPointsTheBridgeAtTheEndpointAndAsksForTheToken(): void
    {
        self::assertSame(
            [
                'manifest_version' => '0.3',
                'name' => 'magento-mcp-shop-example-com',
                'display_name' => 'Magento MCP · shop.example.com',
                'version' => '1.0.0',
                'description' => 'Connects Claude to the Magento store at shop.example.com through its MCP server.',
                'author' => ['name' => 'Volodymyr Hryvinskyi', 'url' => 'https://github.com/hryvinskyi'],
                'server' => [
                    'type' => 'node',
                    'entry_point' => 'server/index.js',
                    'mcp_config' => [
                        'command' => 'node',
                        'args' => ['${__dirname}/server/index.js'],
                        'env' => [
                            'MAGENTO_MCP_URL' => '${user_config.endpoint}',
                            'MAGENTO_MCP_TOKEN' => '${user_config.token}',
                        ],
                    ],
                ],
                'user_config' => [
                    'endpoint' => [
                        'type' => 'string',
                        'title' => 'Store endpoint',
                        'description' => 'The MCP endpoint of the store. Change it only to point this extension at '
                            . 'another copy of the store.',
                        'required' => true,
                        'default' => self::ENDPOINT,
                    ],
                    'token' => [
                        'type' => 'string',
                        'title' => 'Access token',
                        'description' => 'An integration\'s access token with the "Use the MCP endpoint" resource. '
                            . 'Claude keeps it in the system\'s keychain.',
                        'required' => true,
                        'sensitive' => true,
                    ],
                ],
                'compatibility' => [
                    'platforms' => ['darwin', 'win32', 'linux'],
                    'runtimes' => ['node' => '>=18.0.0'],
                ],
            ],
            $this->manifest()->build(self::ENDPOINT)
        );
    }

    public function testTheTokenHasNoDefaultSoTheManifestCarriesNoSecret(): void
    {
        self::assertArrayNotHasKey('default', $this->manifest()->build(self::ENDPOINT)['user_config']['token']);
    }

    public function testTheVersionIsSemantic(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $this->manifest()->build(self::ENDPOINT)['version']
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function slugs(): array
    {
        return [
            'dots become dashes, the port is dropped' => [self::ENDPOINT, 'shop-example-com'],
            'runs of other signs become one dash' => ['https://my--shop_.example/', 'my-shop-example'],
            'an IPv6 host loses its brackets and colons' => ['http://[::1]:8080/rest/all/V1/mcp', '1'],
            'digits are kept' => ['https://shop2.example/', 'shop2-example'],
        ];
    }

    #[DataProvider('slugs')]
    public function testTheSlugIsTheHostInLowerCaseWithDashes(string $url, string $slug): void
    {
        self::assertSame($slug, $this->manifest()->slug($url));
        self::assertSame('magento-mcp-' . $slug, $this->manifest()->build($url)['name']);
    }

    public function testAnEndpointWithoutAHostIsRefusedWithAMessageForTheAdmin(): void
    {
        $this->expectRefusal(
            'The store\'s endpoint /rest/all/V1/mcp names no host, so the extension cannot point at it. Set the '
            . 'default store view\'s secure base URL.'
        );

        $this->manifest()->build('/rest/all/V1/mcp');
    }

    public function testAHostWithNoLetterOrDigitIsRefusedBecauseTheExtensionCannotBeNamedAfterIt(): void
    {
        $this->expectRefusal(
            'The host of the store\'s endpoint https://[::]/rest/all/V1/mcp has no letter or digit, so the '
            . 'extension cannot be named after it. Set the default store view\'s secure base URL to an address '
            . 'with a host name.'
        );

        $this->manifest()->build('https://[::]/rest/all/V1/mcp');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plainHttpToAnotherHost(): array
    {
        return [
            'a shop host' => ['http://shop.example/rest/all/V1/mcp'],
            'an upper-case scheme' => ['HTTP://Shop.Example/rest/all/V1/mcp'],
            'a host that only starts like a loopback name' => ['http://localhost.shop.example/rest/all/V1/mcp'],
            'another loopback address than the one the bridge accepts' => ['http://127.0.0.2/rest/all/V1/mcp'],
            'the unspecified IPv6 address' => ['http://[::]/rest/all/V1/mcp'],
        ];
    }

    #[DataProvider('plainHttpToAnotherHost')]
    public function testPlainHttpToAHostOtherThanThisMachineIsRefusedBecauseTheTokenWouldTravelInClear(
        string $url
    ): void {
        $this->expectRefusal(
            'The store\'s endpoint ' . $url . ' uses plain http to a host other than this computer, so the '
            . 'extension would send the access token unencrypted and refuses to start. Set the default store '
            . 'view\'s secure base URL to an https address.'
        );

        $this->manifest()->build($url);
    }

    #[DataProvider('plainHttpToAnotherHost')]
    public function testPlainHttpToAHostOtherThanThisMachineHasNoSlug(string $url): void
    {
        $this->expectException(LocalizedException::class);

        $this->manifest()->slug($url);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function plainHttpToThisMachine(): array
    {
        return [
            'localhost with a port' => ['http://localhost:8080/rest/all/V1/mcp', 'localhost'],
            'localhost in upper case' => ['http://LOCALHOST/rest/all/V1/mcp', 'localhost'],
            'the IPv4 loopback address' => ['http://127.0.0.1/rest/all/V1/mcp', '127-0-0-1'],
            'the IPv6 loopback address' => ['http://[::1]/rest/all/V1/mcp', '1'],
        ];
    }

    #[DataProvider('plainHttpToThisMachine')]
    public function testPlainHttpToThisMachineIsAccepted(string $url, string $slug): void
    {
        self::assertSame('magento-mcp-' . $slug, $this->manifest()->build($url)['name']);
        self::assertSame($slug, $this->manifest()->slug($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherSchemes(): array
    {
        return [
            'ftp' => ['ftp://shop.example/rest/all/V1/mcp'],
            'websocket' => ['wss://shop.example/rest/all/V1/mcp'],
            'a scheme that only starts like https' => ['httpsx://shop.example/rest/all/V1/mcp'],
        ];
    }

    #[DataProvider('otherSchemes')]
    public function testASchemeOtherThanHttpsOrHttpIsRefused(string $url): void
    {
        $this->expectRefusal(
            'The store\'s endpoint ' . $url . ' is neither an https nor an http address, so the extension cannot '
            . 'reach it. Set the default store view\'s secure base URL to an https address.'
        );

        $this->manifest()->build($url);
    }

    #[DataProvider('otherSchemes')]
    public function testASchemeOtherThanHttpsOrHttpHasNoSlug(string $url): void
    {
        $this->expectException(LocalizedException::class);

        $this->manifest()->slug($url);
    }

    /**
     * Endpoints that carry a user name, a password or both before their host
     *
     * @return array<string, array{string}>
     */
    public static function userInfo(): array
    {
        return [
            'a user name and a password' => ['https://user:secret@shop.example/rest/all/V1/mcp'],
            'a user name alone' => ['https://user@shop.example/rest/all/V1/mcp'],
            'an empty user name' => ['https://@shop.example/rest/all/V1/mcp'],
            'plain http to this computer' => ['http://user:secret@localhost/rest/all/V1/mcp'],
        ];
    }

    #[DataProvider('userInfo')]
    public function testAnEndpointCarryingAUserNameOrPasswordIsRefusedBecauseTheBridgeWouldNotStart(
        string $url
    ): void {
        $this->expectRefusal(
            'The store\'s endpoint ' . $url . ' carries a user name or a password, which the extension refuses to '
            . 'use. Set the default store view\'s secure base URL without them.'
        );

        $this->manifest()->build($url);
    }

    #[DataProvider('userInfo')]
    public function testAnEndpointCarryingAUserNameOrPasswordHasNoSlug(string $url): void
    {
        $this->expectException(LocalizedException::class);

        $this->manifest()->slug($url);
    }

    public function testAnAtSignInThePathIsNoUserName(): void
    {
        self::assertSame('shop-example', $this->manifest()->slug('https://shop.example/rest/all/V1/mcp?a=b@c'));
    }

    /**
     * Expect the manifest to refuse the endpoint with exactly this message for the admin
     *
     * @param string $message
     * @return void
     */
    private function expectRefusal(string $message): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, '/') . '$/');
    }

    /**
     * @return ExtensionManifest
     */
    private function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(new UrlHost());
    }
}
