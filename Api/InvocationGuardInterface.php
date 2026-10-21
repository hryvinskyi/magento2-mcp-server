<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;

/**
 * Refuses a call of an ability for the arguments it carries, where exposure and ACL cannot tell
 *
 * The invoker asks every guard after the exposure, ACL and confirmation checks and before the ability runs, so a
 * refused call changes nothing. The first refusal ends the invocation as denied with the reason
 * InvocationResultInterface::DENIAL_ARGUMENT_GUARD and the guard's message. Guards join the invoker through the
 * `guards` argument of Hryvinskyi\McpServer\Model\AbilityInvoker in di.xml.
 *
 * The arguments are the client's, exactly as sent: a guard reads them, never trusts their shape.
 *
 * @api
 */
interface InvocationGuardInterface
{
    /**
     * Why the call is refused, as a message for the client, or null to let it through
     *
     * @param AbilityInterface $ability
     * @param array<string,mixed> $arguments
     * @return string|null
     */
    public function refusal(AbilityInterface $ability, array $arguments): ?string;
}
