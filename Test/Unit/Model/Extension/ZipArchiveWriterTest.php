<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Extension;

use Hryvinskyi\McpServer\Model\Extension\ZipArchiveWriter;
use PHPUnit\Framework\TestCase;

class ZipArchiveWriterTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function testEveryFileSurvivesTheRoundTripInInputOrder(): void
    {
        $files = [
            'manifest.json' => '{"name":"magento","version":"1.0.0"}',
            'server/index.js' => str_repeat("console.log(1);\n", 200),
            'tiny.txt' => 'x',
        ];

        $zip = $this->open((new ZipArchiveWriter())->archive($files));

        self::assertSame(3, $zip->numFiles);
        self::assertSame(array_keys($files), $this->namesInOrder($zip));
        foreach ($files as $path => $content) {
            self::assertSame($content, $zip->getFromName($path), 'The content of ' . $path);
        }
        self::assertSame(8, $this->stat($zip, 'server/index.js')['comp_method'], 'Repetitive content is deflated');
        self::assertSame(0, $this->stat($zip, 'tiny.txt')['comp_method'], 'Content that does not shrink is stored');
        self::assertSame(0, $this->stat($zip, 'manifest.json')['comp_method']);
    }

    public function testTheSameInputGivesIdenticalBytes(): void
    {
        $files = ['a.txt' => str_repeat('abc', 100), 'dir/b.txt' => 'b'];
        $writer = new ZipArchiveWriter();

        self::assertSame($writer->archive($files), $writer->archive($files));
        self::assertSame($writer->archive($files), (new ZipArchiveWriter())->archive($files));
    }

    public function testEntriesCarryAFixedTimestampAndPermissions(): void
    {
        $zip = $this->open((new ZipArchiveWriter())->archive(['a.txt' => 'a']));

        self::assertSame(
            (int)strtotime('1980-01-01 00:00:00'),
            $this->stat($zip, 'a.txt')['mtime'],
            'The timestamp is the zip epoch, not the time of writing'
        );
        $opsys = 0;
        $attributes = 0;
        self::assertTrue($zip->getExternalAttributesName('a.txt', $opsys, $attributes));
        self::assertSame(\ZipArchive::OPSYS_UNIX, $opsys);
        self::assertIsInt($attributes);
        self::assertSame(0100644, $attributes >> 16);
    }

    public function testAnEmptyListGivesAValidEmptyArchive(): void
    {
        $bytes = (new ZipArchiveWriter())->archive([]);

        self::assertSame(22, strlen($bytes), 'Only the end record is written');
        self::assertSame(0, $this->open($bytes)->numFiles);
    }

    public function testAnEmptyFileSurvivesTheRoundTrip(): void
    {
        $zip = $this->open((new ZipArchiveWriter())->archive(['empty.txt' => '']));

        self::assertSame('', $zip->getFromName('empty.txt'));
        self::assertSame(0, $this->stat($zip, 'empty.txt')['comp_method']);
    }

    public function testAUtf8FileNameSurvivesTheRoundTrip(): void
    {
        $name = 'docs/привіт-世界.txt';

        $zip = $this->open((new ZipArchiveWriter())->archive([$name => 'hello']));

        self::assertSame([$name], $this->namesInOrder($zip));
        self::assertSame('hello', $zip->getFromName($name));
    }

    /**
     * @dataProvider invalidPaths
     */
    public function testAnInvalidPathIsRefusedAndNamed(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($path === '' ? 'empty' : '"' . $path . '"');

        (new ZipArchiveWriter())->archive(['ok.txt' => 'fine', $path => 'bad']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPaths(): array
    {
        return [
            'absolute' => ['/abs'],
            'parent segment' => ['a/../b'],
            'current segment' => ['./a'],
            'backslash' => ['a\\b'],
            'empty' => [''],
            'empty segment' => ['a//b'],
            'trailing slash' => ['a/'],
        ];
    }

    public function testAPathThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ZipArchiveWriter())->archive(["bad\xFF.txt" => 'x']);
    }

    /**
     * Writes the bytes to a temporary file and opens that as a zip archive
     *
     * @param string $bytes
     * @return \ZipArchive
     */
    private function open(string $bytes): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('The zip extension is needed to read the archive back');
        }
        $file = tempnam(sys_get_temp_dir(), 'zipwriter');
        self::assertIsString($file);
        $this->tempFiles[] = $file;
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS), 'The archive opens and is consistent');

        return $zip;
    }

    /**
     * @param \ZipArchive $zip
     * @return list<string>
     */
    private function namesInOrder(\ZipArchive $zip): array
    {
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param \ZipArchive $zip
     * @param string $name
     * @return array{
     *     name: string,
     *     index: int,
     *     crc: int,
     *     size: int,
     *     mtime: int,
     *     comp_size: int,
     *     comp_method: int,
     *     encryption_method: int
     * }
     */
    private function stat(\ZipArchive $zip, string $name): array
    {
        $stat = $zip->statName($name);
        self::assertIsArray($stat);

        return $stat;
    }
}
