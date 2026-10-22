<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ProcessFactory;

/**
 * Runs child processes through Symfony's Process component
 *
 * The process is always built from the argument list; the module never builds a command string. Symfony's Process
 * escapes every argument itself: recent versions hand the list to the operating system as it is, and older ones,
 * which still start it through `/bin/sh`, quote each argument first, so no argument is ever read as shell syntax.
 * The list holds only strings under integer keys, which the generated factory hands to the process as they are.
 */
class SymfonyProcessRunner implements ProcessRunnerInterface
{
    /**
     * @param ProcessFactory $processFactory
     * @param ProcessResultFactory $resultFactory
     */
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly ProcessResultFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(array $command, string $input, int $timeoutSeconds): ProcessResult
    {
        $process = $this->processFactory->create([
            'command' => $command,
            'input' => $input,
            'timeout' => (float) $timeoutSeconds,
        ]);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->resultFactory->create([
                'exitCode' => null,
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
                'timedOut' => true,
            ]);
        } catch (ProcessException $failure) {
            return $this->resultFactory->create([
                'exitCode' => null,
                'errorOutput' => $failure->getMessage(),
            ]);
        }

        return $this->resultFactory->create([
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
        ]);
    }
}
