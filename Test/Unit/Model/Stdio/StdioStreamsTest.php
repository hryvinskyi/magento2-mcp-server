<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Stdio\StdioStreams;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\TestCase;

/**
 * Bounded reads and raw writes of the stdio streams over in-memory streams and Magento's filesystem driver
 */
class StdioStreamsTest extends TestCase
{
    public function testLinesComeBackWithoutTheirNewlineAndNullAtTheEnd(): void
    {
        $stream = $this->stream("one\n\nthree");
        $streams = new StdioStreams(new File());

        self::assertSame('one', $streams->readLine($stream, 10));
        self::assertSame('', $streams->readLine($stream, 10));
        self::assertSame('three', $streams->readLine($stream, 10));
        self::assertNull($streams->readLine($stream, 10));
    }

    public function testALineOverTheLimitComesBackAsLimitPlusOneBytesAndItsRestIsDropped(): void
    {
        $stream = $this->stream(str_repeat('a', 300000) . "\nnext\n");
        $streams = new StdioStreams(new File());

        self::assertSame(str_repeat('a', 11), $streams->readLine($stream, 10));
        self::assertSame('next', $streams->readLine($stream, 10));
        self::assertNull($streams->readLine($stream, 10));
    }

    public function testALineOfOneByteOverTheLimitDropsOnlyItsNewline(): void
    {
        $stream = $this->stream(str_repeat('b', 11) . "\nnext\n");
        $streams = new StdioStreams(new File());

        self::assertSame(str_repeat('b', 11), $streams->readLine($stream, 10));
        self::assertSame('next', $streams->readLine($stream, 10));
    }

    public function testAnOverLongLastLineWithoutNewlineEndsTheInput(): void
    {
        $stream = $this->stream(str_repeat('c', 70000));
        $streams = new StdioStreams(new File());

        self::assertSame(str_repeat('c', 11), $streams->readLine($stream, 10));
        self::assertNull($streams->readLine($stream, 10));
    }

    public function testReadAllStopsOneByteAfterTheLimit(): void
    {
        $streams = new StdioStreams(new File());

        self::assertSame('abc', $streams->readAll($this->stream('abc'), 10));
        self::assertSame(str_repeat('d', 11), $streams->readAll($this->stream(str_repeat('d', 50000)), 10));
        self::assertSame('', $streams->readAll($this->stream(''), 10));
    }

    public function testWritesAreRaw(): void
    {
        $stream = $this->stream('');

        (new StdioStreams(new File()))->write($stream, "<info>\\<x</info>\n");

        rewind($stream);
        self::assertSame("<info>\\<x</info>\n", stream_get_contents($stream));
    }

    /**
     * @param string $text
     * @return resource
     */
    private function stream(string $text)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }
}
