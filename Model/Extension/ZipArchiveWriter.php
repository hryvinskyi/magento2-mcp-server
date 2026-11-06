<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Extension;

/**
 * Writes a zip archive in memory, in pure PHP
 *
 * Needs neither the zip extension nor a temporary file. Every entry carries a fixed timestamp (1980-01-01 00:00) and
 * fixed permissions (0644), so the output depends on the files and their order only. A file is deflated when that
 * makes it smaller and stored as it is otherwise. The archive is plain zip: the 64-bit extension is not written, so
 * more than 65 535 entries or any size or offset of 4 GiB or more is refused.
 *
 * @phpstan-type Entry array{name: string, method: int, crc: int, data: string, size: int}
 */
class ZipArchiveWriter implements ArchiveWriterInterface
{
    private const LOCAL_HEADER_SIGNATURE = 0x04034b50;
    private const CENTRAL_HEADER_SIGNATURE = 0x02014b50;
    private const END_RECORD_SIGNATURE = 0x06054b50;
    private const VERSION_NEEDED = 20;
    private const VERSION_MADE_BY = 0x0314;
    private const FLAG_UTF8_NAMES = 0x0800;
    private const METHOD_STORED = 0;
    private const METHOD_DEFLATED = 8;
    private const DOS_TIME = 0;
    private const DOS_DATE = (0 << 9) | (1 << 5) | 1;
    private const EXTERNAL_ATTRIBUTES = 0100644 << 16;
    private const COMPRESSION_LEVEL = 9;
    private const MAX_ENTRIES = 0xFFFF;
    private const MAX_NAME_LENGTH = 0xFFFF;
    private const FOUR_GIB_MARKER = 0xFFFFFFFF;

    /**
     * @inheritDoc
     */
    public function archive(array $files): string
    {
        if (count($files) > self::MAX_ENTRIES) {
            throw new \InvalidArgumentException(
                sprintf('A zip archive without the 64-bit extension holds at most %d files', self::MAX_ENTRIES)
            );
        }

        $body = '';
        $directory = '';
        foreach ($files as $path => $content) {
            $name = (string)$path;
            $this->assertValidPath($name);
            $entry = $this->describeEntry($name, $content);
            $this->assertFits($name, 'offset', strlen($body));
            $directory .= $this->centralDirectoryEntry($entry, strlen($body));
            $body .= $this->localHeader($entry) . $entry['data'];
        }
        $this->assertFits('', 'archive size', strlen($body) + strlen($directory));

        return $body . $directory . $this->endRecord(count($files), strlen($directory), strlen($body));
    }

    /**
     * Rejects a path that is not a plain relative path made of forward-slash separated names
     *
     * @param string $path
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertValidPath(string $path): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException('An archive path must not be empty');
        }
        if (str_contains($path, '\\')) {
            throw new \InvalidArgumentException(sprintf('The archive path "%s" holds a backslash', $path));
        }
        if (str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(sprintf('The archive path "%s" is absolute', $path));
        }
        if (str_ends_with($path, '/')) {
            throw new \InvalidArgumentException(sprintf('The archive path "%s" names a directory', $path));
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException(sprintf('The archive path "%s" holds an empty segment', $path));
            }
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException(
                    sprintf('The archive path "%s" holds a "%s" segment', $path, $segment)
                );
            }
        }
        if (preg_match('//u', $path) !== 1) {
            throw new \InvalidArgumentException(sprintf('The archive path "%s" is not valid UTF-8', $path));
        }
        if (strlen($path) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('An archive path is longer than a zip entry name can be');
        }
    }

    /**
     * Compresses the content when that helps and gathers the numbers both headers repeat
     *
     * @param string $name
     * @param string $content
     * @return Entry
     * @throws \InvalidArgumentException
     */
    private function describeEntry(string $name, string $content): array
    {
        $deflated = gzdeflate($content, self::COMPRESSION_LEVEL);
        $useDeflate = $deflated !== false && strlen($deflated) < strlen($content);
        $data = $useDeflate ? (string)$deflated : $content;
        $this->assertFits($name, 'size', strlen($content));
        $this->assertFits($name, 'compressed size', strlen($data));

        return [
            'name' => $name,
            'method' => $useDeflate ? self::METHOD_DEFLATED : self::METHOD_STORED,
            'crc' => crc32($content),
            'data' => $data,
            'size' => strlen($content),
        ];
    }

    /**
     * Refuses a number that does not fit the 32-bit fields of a plain zip archive
     *
     * @param string $name Entry the number belongs to, empty for the archive as a whole
     * @param string $what
     * @param int $value
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertFits(string $name, string $what, int $value): void
    {
        if ($value < self::FOUR_GIB_MARKER) {
            return;
        }
        $subject = $name === '' ? sprintf('The %s', $what) : sprintf('The %s of "%s"', $what, $name);
        throw new \InvalidArgumentException(
            $subject . ' reaches 4 GiB, which a zip archive without the 64-bit extension cannot hold'
        );
    }

    /**
     * The header that precedes an entry's data
     *
     * @param Entry $entry
     * @return string
     */
    private function localHeader(array $entry): string
    {
        return pack('V', self::LOCAL_HEADER_SIGNATURE)
            . pack('v', self::VERSION_NEEDED)
            . pack('v', self::FLAG_UTF8_NAMES)
            . pack('v', $entry['method'])
            . pack('v', self::DOS_TIME)
            . pack('v', self::DOS_DATE)
            . pack('V', $entry['crc'])
            . pack('V', strlen($entry['data']))
            . pack('V', $entry['size'])
            . pack('v', strlen($entry['name']))
            . pack('v', 0)
            . $entry['name'];
    }

    /**
     * The central directory record that points at an entry's local header
     *
     * @param Entry $entry
     * @param int $localHeaderOffset
     * @return string
     */
    private function centralDirectoryEntry(array $entry, int $localHeaderOffset): string
    {
        return pack('V', self::CENTRAL_HEADER_SIGNATURE)
            . pack('v', self::VERSION_MADE_BY)
            . pack('v', self::VERSION_NEEDED)
            . pack('v', self::FLAG_UTF8_NAMES)
            . pack('v', $entry['method'])
            . pack('v', self::DOS_TIME)
            . pack('v', self::DOS_DATE)
            . pack('V', $entry['crc'])
            . pack('V', strlen($entry['data']))
            . pack('V', $entry['size'])
            . pack('v', strlen($entry['name']))
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', self::EXTERNAL_ATTRIBUTES)
            . pack('V', $localHeaderOffset)
            . $entry['name'];
    }

    /**
     * The record that closes the archive and tells a reader where the central directory is
     *
     * @param int $entries
     * @param int $directorySize
     * @param int $directoryOffset
     * @return string
     */
    private function endRecord(int $entries, int $directorySize, int $directoryOffset): string
    {
        return pack('V', self::END_RECORD_SIGNATURE)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $entries)
            . pack('v', $entries)
            . pack('V', $directorySize)
            . pack('V', $directoryOffset)
            . pack('v', 0);
    }
}
