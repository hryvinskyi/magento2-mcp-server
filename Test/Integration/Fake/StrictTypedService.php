<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

/**
 * An implementation stricter than its contract: it hands the text the contract promises to take on to an operation
 * that takes an int, so a call fails with a \TypeError
 */
class StrictTypedService implements TypedServiceInterface
{
    /**
     * @var \Closure
     */
    private \Closure $twice;

    public function __construct()
    {
        $this->twice = static fn (int $number): int => $number * 2;
    }

    /**
     * @param string $value
     * @return mixed
     */
    public function double($value)
    {
        return ($this->twice)($value);
    }
}
