<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

/**
 * The PHP server API this process runs under, `cli` for a command line
 */
class SapiName
{
    private const CLI = 'cli';

    /**
     * @param string|null $name The server API's name; when not given, the running process's own, read when asked.
     *     It is never a default value of its own: production's compiled configuration keeps a default as the
     *     command line that compiled it saw it, so every web request would read as a command line.
     */
    public function __construct(
        private readonly ?string $name = null
    ) {
    }

    /**
     * Whether the process is a command line
     *
     * @return bool
     */
    public function isCommandLine(): bool
    {
        return ($this->name ?? PHP_SAPI) === self::CLI;
    }
}
