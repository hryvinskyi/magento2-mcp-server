<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Stdio\ProcessResult;
use Hryvinskyi\McpServer\Model\Stdio\ProcessRunnerInterface;

/**
 * Answers each run with the next scripted result, and records every command, input and time limit it was given
 *
 * When the script runs out, a process that exits 0 and writes nothing is reported.
 */
class ScriptedProcessRunner implements ProcessRunnerInterface
{
    /**
     * Every run, in order
     *
     * @var list<array{command: list<string>, input: string, timeout: int}>
     */
    public array $runs = [];

    /**
     * @param list<ProcessResult> $results
     */
    public function __construct(
        private array $results = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(array $command, string $input, int $timeoutSeconds): ProcessResult
    {
        $this->runs[] = ['command' => $command, 'input' => $input, 'timeout' => $timeoutSeconds];

        return array_shift($this->results) ?? new ProcessResult(0);
    }
}
