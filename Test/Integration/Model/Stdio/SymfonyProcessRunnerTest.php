<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Stdio;

use Hryvinskyi\McpServer\Model\Stdio\ProcessRunnerInterface;
use Hryvinskyi\McpServer\Model\Stdio\SymfonyProcessRunner;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The process runner the container wires runs real child processes from an argument list
 */
class SymfonyProcessRunnerTest extends TestCase
{
    use BlocksRealGraphQlClient;

    public function testAChildReadsItsInputAndItsOutputAndExitCodeComeBack(): void
    {
        $result = $this->runner()->run(
            [PHP_BINARY, '-r', 'echo fgets(STDIN); fwrite(STDERR, "to stderr"); exit(3);'],
            "hello; echo injected\n",
            30
        );

        self::assertSame(3, $result->getExitCode());
        self::assertSame("hello; echo injected\n", $result->getOutput());
        self::assertSame('to stderr', $result->getErrorOutput());
        self::assertFalse($result->isTimedOut());
    }

    public function testAnArgumentIsNeverReadByAShell(): void
    {
        $result = $this->runner()->run([PHP_BINARY, '-r', 'echo $argv[1];', '$(echo x); `id`'], '', 30);

        self::assertSame(0, $result->getExitCode());
        self::assertSame('$(echo x); `id`', $result->getOutput());
    }

    public function testAChildThatRunsPastItsTimeLimitIsReportedAsTimedOut(): void
    {
        $started = hrtime(true);

        $result = $this->runner()->run([PHP_BINARY, '-r', 'echo "started"; flush(); sleep(30);'], '', 1);

        self::assertTrue($result->isTimedOut());
        self::assertNull($result->getExitCode());
        self::assertLessThan(10, (hrtime(true) - $started) / 1e9);
    }

    public function testAProgramThatCannotBeStartedIsReportedWithoutAnExitCode(): void
    {
        $result = $this->runner()->run(['/nonexistent/mcp-program'], '', 5);

        self::assertFalse($result->isTimedOut());
        self::assertNotSame(0, $result->getExitCode());
    }

    /**
     * @return ProcessRunnerInterface
     */
    private function runner(): ProcessRunnerInterface
    {
        $runner = Bootstrap::getObjectManager()->get(ProcessRunnerInterface::class);
        self::assertInstanceOf(SymfonyProcessRunner::class, $runner);

        return $runner;
    }
}
