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
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;

/**
 * A denied call is a JSON-RPC error naming the ability and why it was denied
 *
 * The reason is the invoker's: `deny_list`, `read_only_mode`, `anonymous_ability`, `acl`, `argument_guard`, or the
 * code of an exposure rule another module adds. Retrying the same call cannot lift a denial, so it is not a tool
 * error the model would retry.
 */
class DeniedAnswer implements OutcomeAnswerInterface
{
    /**
     * @inheritDoc
     */
    public function answer(InvocationInterface $invocation, InvocationResultInterface $result): array
    {
        throw new JsonRpcErrorException(
            ErrorCode::AbilityDenied,
            $result->getMessage(),
            ['ability' => $invocation->getAbilityName(), 'reason' => $result->getDenialReason()]
        );
    }
}
