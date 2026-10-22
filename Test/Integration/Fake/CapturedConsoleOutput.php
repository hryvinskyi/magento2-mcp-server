<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A console output whose standard output and standard error are two in-memory streams, so a test reads each one
 * apart, as a terminal shows them
 */
class CapturedConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    /**
     * @var OutputInterface
     */
    private OutputInterface $errorOutput;

    public function __construct()
    {
        parent::__construct(self::memory());
        $this->errorOutput = new StreamOutput(self::memory());
    }

    /**
     * @inheritDoc
     */
    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    /**
     * @inheritDoc
     */
    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    /**
     * @inheritDoc
     */
    public function section(): ConsoleSectionOutput
    {
        throw new \LogicException('A captured output has no sections.');
    }

    /**
     * Everything written to standard output
     *
     * @return string
     */
    public function written(): string
    {
        return self::read($this->getStream());
    }

    /**
     * Everything written to standard error
     *
     * @return string
     */
    public function writtenToErrorOutput(): string
    {
        $errorOutput = $this->errorOutput;
        if (!$errorOutput instanceof StreamOutput) {
            return '';
        }

        return self::read($errorOutput->getStream());
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('No memory stream.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     * @return string
     */
    private static function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
