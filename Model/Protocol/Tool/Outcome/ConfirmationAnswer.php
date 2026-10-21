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
use Hryvinskyi\McpServer\Model\Confirmation\ConfirmationStoreInterface;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;

/**
 * A call that needs a confirmation is a JSON-RPC error carrying a new confirmation token for exactly that call
 *
 * The token is bound to the invocation's session, caller, ability and arguments, and the client confirms by making
 * the same call again with it. The client is expected to ask its user before it does; the token proves only that the
 * client made a second, explicit call for those arguments.
 */
class ConfirmationAnswer implements OutcomeAnswerInterface
{
    /**
     * @param ConfirmationStoreInterface $confirmations
     */
    public function __construct(
        private readonly ConfirmationStoreInterface $confirmations
    ) {
    }

    /**
     * @inheritDoc
     */
    public function answer(InvocationInterface $invocation, InvocationResultInterface $result): array
    {
        $confirmation = $this->confirmations->issue(
            $invocation->getSessionId(),
            $invocation->getCaller(),
            $invocation->getAbilityName(),
            $invocation->getArguments()
        );

        throw new JsonRpcErrorException(
            ErrorCode::ConfirmationRequired,
            __(
                'The ability "%1" needs a confirmation: ask the user, then call it again within ten minutes with the '
                . 'same name and arguments plus "confirmationToken": "%2".',
                $invocation->getAbilityName(),
                $confirmation->getToken()
            )->render(),
            ['ability' => $invocation->getAbilityName(), 'confirmationToken' => $confirmation->getToken()]
        );
    }
}
