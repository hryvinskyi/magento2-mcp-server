<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

/**
 * Runs a program as a child process and waits for it
 */
interface ProcessRunnerInterface
{
    /**
     * Run the program with this input on its standard input, and report how it ended
     *
     * The command is the program followed by its arguments, each passed to it as it is: no shell reads them. A
     * process that runs past the time limit is stopped and reported as timed out; one that cannot be started is
     * reported with no exit code. Neither throws.
     *
     * @param list<string> $command
     * @param string $input
     * @param int $timeoutSeconds
     * @return ProcessResult
     */
    public function run(array $command, string $input, int $timeoutSeconds): ProcessResult;
}
