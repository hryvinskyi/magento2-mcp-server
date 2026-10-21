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
 * A call that failed is a tool error carrying the invoker's message
 *
 * That message is the one meant for the client: the service's own for arguments it cannot take or a refusal it
 * explains, and a masked one for anything else, so the model can correct its arguments or report the failure.
 */
class FailedAnswer implements OutcomeAnswerInterface
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
        return $this->toolResults->error($result->getMessage());
    }
}
