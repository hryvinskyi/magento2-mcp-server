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
     * @param string $name The server API's name; PHP's own when not given
     */
    public function __construct(
        private readonly string $name = PHP_SAPI
    ) {
    }

    /**
     * Whether the process is a command line
     *
     * @return bool
     */
    public function isCommandLine(): bool
    {
        return $this->name === self::CLI;
    }
}
