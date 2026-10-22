<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\DriverInterface;

/**
 * Reads and writes the streams of the stdio transport through Magento's filesystem driver
 *
 * - Reads are bounded: a read never holds more than its limit plus one byte, so an endless line or message cannot
 *   fill the memory before it is refused. A result longer than the limit tells the caller the input was too long.
 * - Writes are raw and flushed at once: nothing rewrites the text on its way, as a console formatter would rewrite
 *   `<tag>` and `\<` sequences inside JSON.
 */
class StdioStreams
{
    private const NEWLINE = "\n";
    private const DRAIN_CHUNK_BYTES = 65536;

    /**
     * @param DriverInterface $driver
     */
    public function __construct(
        private readonly DriverInterface $driver
    ) {
    }

    /**
     * The process's standard input, opened for reading
     *
     * @return resource
     * @throws FileSystemException When it cannot be opened
     */
    public function standardInput(): mixed
    {
        return $this->driver->fileOpen('php://stdin', 'r');
    }

    /**
     * The process's standard output, opened for writing
     *
     * @return resource
     * @throws FileSystemException When it cannot be opened
     */
    public function standardOutput(): mixed
    {
        return $this->driver->fileOpen('php://stdout', 'w');
    }

    /**
     * The process's standard error, opened for writing
     *
     * @return resource
     * @throws FileSystemException When it cannot be opened
     */
    public function standardError(): mixed
    {
        return $this->driver->fileOpen('php://stderr', 'w');
    }

    /**
     * The next line without its newline, or null at the end of the input
     *
     * A line longer than the limit comes back as its first limit + 1 bytes, and the rest of it up to its newline is
     * read and dropped in bounded chunks, so the next call returns the next line.
     *
     * @param resource $stream
     * @param int $limit The longest line accepted, in bytes
     * @return string|null
     * @throws FileSystemException When the stream cannot be read
     */
    public function readLine(mixed $stream, int $limit): ?string
    {
        $line = $this->readUpToNewline($stream, $limit + 1);
        if ($line === null || strlen($line) <= $limit) {
            return $line;
        }

        $this->dropRestOfLine($stream);

        return $line;
    }

    /**
     * Everything up to the end of the input, but never more than the limit + 1 bytes
     *
     * @param resource $stream
     * @param int $limit The longest input accepted, in bytes
     * @return string
     * @throws FileSystemException When the stream cannot be read
     */
    public function readAll(mixed $stream, int $limit): string
    {
        $read = '';
        while (strlen($read) <= $limit && !$this->driver->endOfFile($stream)) {
            $read .= $this->driver->fileRead($stream, $limit + 1 - strlen($read));
        }

        return $read;
    }

    /**
     * Write the text as it is and flush it
     *
     * @param resource $stream
     * @param string $text
     * @return void
     * @throws FileSystemException When the stream cannot be written
     */
    public function write(mixed $stream, string $text): void
    {
        $this->driver->fileWrite($stream, $text);
        $this->driver->fileFlush($stream);
    }

    /**
     * Up to this many bytes of the current line, without the newline, or null at the end of the input
     *
     * The driver throws when nothing is left to read; at the end of the input that is no failure.
     *
     * @param resource $stream
     * @param int $length
     * @return string|null
     * @throws FileSystemException When the stream cannot be read
     */
    private function readUpToNewline(mixed $stream, int $length): ?string
    {
        try {
            return $this->driver->fileReadLine($stream, $length, self::NEWLINE);
        } catch (FileSystemException $failure) {
            if ($this->driver->endOfFile($stream)) {
                return null;
            }

            throw $failure;
        }
    }

    /**
     * Read and drop the rest of the current line, newline included, in bounded chunks
     *
     * @param resource $stream
     * @return void
     * @throws FileSystemException When the stream cannot be read
     */
    private function dropRestOfLine(mixed $stream): void
    {
        do {
            $chunk = $this->readUpToNewline($stream, self::DRAIN_CHUNK_BYTES);
        } while ($chunk !== null && strlen($chunk) === self::DRAIN_CHUNK_BYTES);
    }
}
