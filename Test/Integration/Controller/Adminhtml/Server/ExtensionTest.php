<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Model\Admin\ClientConnectionInterface;
use Hryvinskyi\McpServer\Model\Extension\ClaudeDesktopExtension;
use Laminas\Http\Header\HeaderInterface;
use Magento\Backend\Model\Auth\StorageInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Module\Dir\Reader;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Downloading the Claude Desktop extension through the real admin stack, ACL and wiring
 *
 * The download carries no secret: the token is a setting Claude Desktop asks for at install time.
 */
class ExtensionTest extends AbstractBackendController
{
    private const BRIDGE_FILES = [
        'server/index.js',
        'server/bridge.js',
        'server/http-transport.js',
        'server/line-reader.js',
        'server/messages.js',
    ];

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::view';

    /**
     * @var string
     */
    protected $uri = 'backend/mcpserver/server/extension';

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    /**
     * Log in, and make the download the next page rather than the first page after login, which redirects
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $session = $this->_session;
        self::assertInstanceOf(StorageInterface::class, $session);
        $session->setIsFirstPageAfterLogin(false);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->_objectManager->removeSharedInstance(ClaudeDesktopExtension::class);
        $driver = $this->driver();
        foreach ($this->tempFiles as $file) {
            if ($driver->isExists($file)) {
                $driver->deleteFile($file);
            }
        }
        $this->tempFiles = [];
        foreach ($this->tempDirs as $dir) {
            if ($driver->isExists($dir)) {
                $driver->deleteDirectory($dir);
            }
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public function testTheDownloadIsAnExtensionFileThatIsNeverCached(): void
    {
        $response = $this->download();

        self::assertSame(200, $response->getHttpResponseCode());
        self::assertSame('application/zip', $this->header($response, 'Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="magento-mcp-[a-z0-9-]+\.mcpb"$/',
            $this->header($response, 'Content-Disposition')
        );
        self::assertSame('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame('nosniff', $this->header($response, 'X-Content-Type-Options'));
    }

    public function testTheExtensionPointsAtTheStoreAndShipsTheModulesBridge(): void
    {
        $zip = $this->open((string) $this->download()->getBody());

        self::assertSame('manifest.json', $zip->getNameIndex(0));
        $endpoint = $this->at($this->manifest($zip), 'user_config', 'endpoint', 'default');
        self::assertIsString($endpoint);
        self::assertStringEndsWith('/rest/all/V1/mcp', $endpoint);
        foreach (self::BRIDGE_FILES as $index => $entry) {
            self::assertSame($entry, $zip->getNameIndex($index + 1));
        }
        self::assertSame(1 + count(self::BRIDGE_FILES), $zip->numFiles);
        $reader = $this->_objectManager->get(Reader::class);
        self::assertInstanceOf(Reader::class, $reader);
        $moduleDir = $reader->getModuleDir('', 'Hryvinskyi_McpServer');
        self::assertSame(
            $this->driver()->fileGetContents($moduleDir . '/extension/server/index.js'),
            $zip->getFromName('server/index.js')
        );
    }

    public function testNoSecretTravelsInTheDownload(): void
    {
        $zip = $this->open((string) $this->download()->getBody());

        $manifest = $this->manifest($zip);
        $token = $this->at($manifest, 'user_config', 'token');
        self::assertIsArray($token);
        self::assertArrayNotHasKey('default', $token);
        self::assertTrue($this->at($token, 'sensitive'));
        self::assertSame(
            '${user_config.token}',
            $this->at($manifest, 'server', 'mcp_config', 'env', 'MAGENTO_MCP_TOKEN')
        );
        for ($index = 0; $index < $zip->numFiles; $index++) {
            self::assertStringNotContainsString('<integration-token>', (string) $zip->getFromIndex($index));
        }
    }

    /**
     * Requiring the bridge's entry point loads every module it requires without starting it, so a file the bridge
     * needs but the download leaves out fails here, as it would on the admin's machine
     *
     * @return void
     */
    public function testTheDownloadedBridgeLoadsInNode(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('No node binary on the PATH, so the downloaded bridge cannot be run here.');
        }

        $dir = $this->unpack($this->open((string) $this->download()->getBody()));
        $process = new Process([$node, '-e', 'require(process.argv[1])', $dir . '/server/index.js']);
        $process->setTimeout(30);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            'The downloaded bridge loads in Node: ' . $process->getErrorOutput()
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function endpointsTheBridgeWouldRefuse(): array
    {
        return [
            'a default store view without a base URL' => [
                '/rest/all/V1/mcp',
                'The store\'s endpoint /rest/all/V1/mcp names no host, so the extension cannot point at it. Set the '
                . 'default store view\'s secure base URL.',
            ],
            'plain http to the shop\'s host' => [
                'http://shop.example/rest/all/V1/mcp',
                'The store\'s endpoint http://shop.example/rest/all/V1/mcp uses plain http to a host other than this '
                . 'computer, so the extension would send the access token unencrypted and refuses to start. Set the '
                . 'default store view\'s secure base URL to an https address.',
            ],
        ];
    }

    #[DataProvider('endpointsTheBridgeWouldRefuse')]
    public function testAnEndpointTheBridgeWouldRefuseSendsTheAdminBackWithTheReason(
        string $endpoint,
        string $reason
    ): void {
        $connection = $this->createStub(ClientConnectionInterface::class);
        $connection->method('serverName')->willReturn('magento');
        $connection->method('endpointUrl')->willReturn($endpoint);
        $this->_objectManager->addSharedInstance(
            $this->_objectManager->create(ClaudeDesktopExtension::class, ['connection' => $connection]),
            ClaudeDesktopExtension::class
        );

        $this->dispatch($this->uri);

        $this->assertRedirect(self::stringContains('mcpserver/server/index'));
        $escaper = $this->_objectManager->get(Escaper::class);
        self::assertInstanceOf(Escaper::class, $escaper);
        $this->assertSessionMessages(
            self::equalTo([$escaper->escapeHtml($reason)]),
            MessageInterface::TYPE_ERROR
        );
    }

    /**
     * The answer to the download
     *
     * @return Response
     */
    private function download(): Response
    {
        $this->dispatch($this->uri);
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * A header's value
     *
     * @param Response $response
     * @param string $name
     * @return string
     */
    private function header(Response $response, string $name): string
    {
        $header = $response->getHeader($name);
        self::assertInstanceOf(HeaderInterface::class, $header, $name . ' is sent');

        return $header->getFieldValue();
    }

    /**
     * The decoded manifest of the archive
     *
     * @param \ZipArchive $zip
     * @return array<mixed>
     */
    private function manifest(\ZipArchive $zip): array
    {
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /**
     * The value at a path of keys inside decoded JSON, which must be there
     *
     * @param mixed $data
     * @param string ...$keys
     * @return mixed
     */
    private function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($data);
            self::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * The archive, opened from a temporary file
     *
     * @param string $bytes
     * @return \ZipArchive
     */
    private function open(string $bytes): \ZipArchive
    {
        $file = tempnam(sys_get_temp_dir(), 'mcpb');
        self::assertIsString($file);
        $this->tempFiles[] = $file;
        $this->driver()->filePutContents($file, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS), 'The download is a consistent zip archive');

        return $zip;
    }

    /**
     * The archive's entries, unpacked into a new temporary directory
     *
     * @param \ZipArchive $zip
     * @return string The directory
     */
    private function unpack(\ZipArchive $zip): string
    {
        $dir = sys_get_temp_dir() . '/mcpb-' . bin2hex(random_bytes(8));
        $this->driver()->createDirectory($dir);
        $this->tempDirs[] = $dir;
        self::assertTrue($zip->extractTo($dir), 'The download unpacks');

        return $dir;
    }

    /**
     * @return File
     */
    private function driver(): File
    {
        $driver = $this->_objectManager->get(File::class);
        self::assertInstanceOf(File::class, $driver);

        return $driver;
    }
}
