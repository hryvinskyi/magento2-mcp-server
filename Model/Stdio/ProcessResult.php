<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

/**
 * How a child process ended and what it wrote, created through ProcessResultFactory
 */
class ProcessResult
{
    /**
     * @param int|null $exitCode The exit code, or null when the process did not end by itself or never started
     * @param string $output What it wrote to its standard output
     * @param string $errorOutput What it wrote to its standard error, or why it could not be run
     * @param bool $timedOut Whether it was stopped for running past its time limit
     */
    public function __construct(
        private readonly ?int $exitCode,
        private readonly string $output = '',
        private readonly string $errorOutput = '',
        private readonly bool $timedOut = false
    ) {
    }

    /**
     * The exit code, or null when the process did not end by itself or never started
     *
     * @return int|null
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * What the process wrote to its standard output
     *
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * What the process wrote to its standard error, or why it could not be run
     *
     * @return string
     */
    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * Whether the process was stopped for running past its time limit
     *
     * @return bool
     */
    public function isTimedOut(): bool
    {
        return $this->timedOut;
    }
}
