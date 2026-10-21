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
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;

/**
 * How `execute_ability` answers one outcome of an invocation
 *
 * The answers are the di.xml pool `outcomeAnswers` of ExecuteAbility, keyed by the outcome's value, so every outcome
 * has exactly one answer and an outcome without one fails loudly.
 */
interface OutcomeAnswerInterface
{
    /**
     * The tool result for the invocation's outcome
     *
     * @param InvocationInterface $invocation
     * @param InvocationResultInterface $result
     * @return array<string, mixed>
     * @throws JsonRpcErrorException When the outcome is answered with a JSON-RPC error instead of a tool result
     */
    public function answer(InvocationInterface $invocation, InvocationResultInterface $result): array;
}
