<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\InvocationListenerInterface;

/**
 * An invocation listener that throws whenever it is told something
 */
class ThrowingListener implements InvocationListenerInterface
{
    /**
     * @inheritDoc
     */
    public function started(InvocationInterface $invocation): void
    {
        throw new \RuntimeException('The listener broke on start.');
    }

    /**
     * @inheritDoc
     */
    public function finished(InvocationInterface $invocation, InvocationResultInterface $result): void
    {
        throw new \TypeError('The listener broke on finish.');
    }
}
