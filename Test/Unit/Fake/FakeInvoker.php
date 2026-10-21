<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\AbilityInvokerInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;

/**
 * An invoker that answers every invocation the way the test says, and keeps the invocations it was given
 */
class FakeInvoker implements AbilityInvokerInterface
{
    /**
     * @var list<InvocationInterface>
     */
    public array $invocations = [];

    /**
     * @param \Closure(InvocationInterface): InvocationResultInterface $answer
     */
    public function __construct(
        private readonly \Closure $answer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function invoke(InvocationInterface $invocation): InvocationResultInterface
    {
        $this->invocations[] = $invocation;

        return ($this->answer)($invocation);
    }
}
