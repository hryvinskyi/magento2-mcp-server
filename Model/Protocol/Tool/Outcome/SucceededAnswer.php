<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool\Outcome;

use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolResults;

/**
 * A call that worked is a tool result holding what the ability returned
 */
class SucceededAnswer implements OutcomeAnswerInterface
{
    /**
     * @param ToolResults $toolResults
     */
    public function __construct(
        private readonly ToolResults $toolResults
    ) {
    }

    /**
     * @inheritDoc
     */
    public function answer(InvocationInterface $invocation, InvocationResultInterface $result): array
    {
        return $this->toolResults->success($result->getValue());
    }
}
