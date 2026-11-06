<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Extension;

use Hryvinskyi\McpServer\Model\Admin\ClientConnectionInterface;
use Hryvinskyi\McpServer\Model\Extension\ClaudeDesktopExtension;
use Hryvinskyi\McpServer\Model\Extension\ExtensionManifest;
use Hryvinskyi\McpServer\Model\Extension\ZipArchiveWriter;
use Hryvinskyi\McpServer\Model\Http\UrlHost;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The `.mcpb` download: the manifest for the store's endpoint first, then the bridge files read from the module
 */
class ClaudeDesktopExtensionTest extends TestCase
{
    private const ENDPOINT = 'https://shop.example/rest/all/V1/mcp';
    private const MODULE_DIR = '/srv/secret-root/app/code/Hryvinskyi/McpServer';

    /**
     * Path inside the extension => path inside the module
     */
    private const FILES = [
        'server/index.js' => 'extension/server/index.js',
        'server/bridge.js' => 'extension/server/bridge.js',
        'server/http-transport.js' => 'extension/server/http-transport.js',
        'server/line-reader.js' => 'extension/server/line-reader.js',
        'server/messages.js' => 'extension/server/messages.js',
    ];

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        $driver = new File();
        foreach ($this->tempFiles as $file) {
            if ($driver->isExists($file)) {
                $driver->deleteFile($file);
            }
        }
        $this->tempFiles = [];
    }

    public function testTheFileIsNamedAfterTheEndpointsHost(): void
    {
        self::assertSame('magento-mcp-shop-example.mcpb', $this->package($this->driver())->fileName());
    }

    public function testTheArchiveHoldsTheManifestFirstThenTheBridgeFiles(): void
    {
        $zip = $this->open($this->package($this->driver())->content());

        self::assertSame(1 + count(self::FILES), $zip->numFiles);
        self::assertSame('manifest.json', $zip->getNameIndex(0));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((new ExtensionManifest(new UrlHost()))->build(self::ENDPOINT), $manifest);
        $index = 1;
        foreach (self::FILES as $entry => $modulePath) {
            self::assertSame($entry, $zip->getNameIndex($index++));
            self::assertSame('content of ' . $modulePath, $zip->getFromName($entry));
        }
    }

    public function testThePlaceholdersReachTheManifestUnescaped(): void
    {
        $json = (string) $this->open($this->package($this->driver())->content())->getFromName('manifest.json');

        self::assertStringContainsString('"${__dirname}/server/index.js"', $json);
        self::assertStringContainsString('"MAGENTO_MCP_TOKEN": "${user_config.token}"', $json);
        self::assertStringContainsString('"default": "' . self::ENDPOINT . '"', $json);
    }

    public function testAMissingBridgeFileIsNamedByItsPathInsideTheModuleOnly(): void
    {
        $driver = $this->driver(missing: self::MODULE_DIR . '/extension/server/messages.js');

        try {
            $this->package($driver)->content();
            self::fail('A missing bridge file must stop the download.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('extension/server/messages.js', $exception->getMessage());
            self::assertStringNotContainsString('secret-root', $exception->getMessage());
        }
    }

    public function testAnUnreadableBridgeFileHidesTheDriversMessageWithTheServerPath(): void
    {
        $driver = $this->driver(unreadable: self::MODULE_DIR . '/extension/server/bridge.js');

        try {
            $this->package($driver)->content();
            self::fail('An unreadable bridge file must stop the download.');
        } catch (LocalizedException $exception) {
            self::assertNotInstanceOf(FileSystemException::class, $exception);
            self::assertStringContainsString('extension/server/bridge.js', $exception->getMessage());
            self::assertStringNotContainsString('secret-root', $exception->getMessage());
        }
    }

    public function testAnEndpointWithoutAHostStopsTheDownloadWithTheAdminsMessage(): void
    {
        $package = $this->package($this->driver(), '/rest/all/V1/mcp');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('names no host');

        $package->content();
    }

    /**
     * The package for an endpoint, with the module at MODULE_DIR
     *
     * @param DriverInterface $driver
     * @param string $endpoint
     * @return ClaudeDesktopExtension
     */
    private function package(DriverInterface $driver, string $endpoint = self::ENDPOINT): ClaudeDesktopExtension
    {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('endpointUrl')->willReturn($endpoint);
        $connection->method('serverName')->willReturn('magento');
        $reader = $this->createMock(Reader::class);
        $reader->method('getModuleDir')->with('', 'Hryvinskyi_McpServer')->willReturn(self::MODULE_DIR);

        return new ClaudeDesktopExtension(
            $connection,
            new ExtensionManifest(new UrlHost()),
            new ZipArchiveWriter(),
            $reader,
            $driver,
            self::FILES
        );
    }

    /**
     * A driver that holds "content of <module path>" for every file under MODULE_DIR
     *
     * @param string|null $missing The absolute path of a file that does not exist
     * @param string|null $unreadable The absolute path of a file whose reading fails
     * @return DriverInterface&MockObject
     */
    private function driver(?string $missing = null, ?string $unreadable = null): DriverInterface&MockObject
    {
        $prefix = self::MODULE_DIR . '/';
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('isExists')->willReturnCallback(
            static fn (string $path): bool => $path !== $missing && str_starts_with($path, $prefix)
        );
        $driver->method('fileGetContents')->willReturnCallback(
            static function (string $path) use ($prefix, $unreadable): string {
                if ($path === $unreadable) {
                    throw new FileSystemException(new Phrase('The file "%1" cannot be read', [$path]));
                }

                return 'content of ' . substr($path, strlen($prefix));
            }
        );

        return $driver;
    }

    /**
     * The archive, opened from a temporary file
     *
     * @param string $bytes
     * @return \ZipArchive
     */
    private function open(string $bytes): \ZipArchive
    {
        $driver = new File();
        $file = tempnam(sys_get_temp_dir(), 'mcpb');
        self::assertIsString($file);
        $this->tempFiles[] = $file;
        $driver->filePutContents($file, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS), 'The archive opens and is consistent');

        return $zip;
    }
}
