<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Confirmation;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;

/**
 * Where the confirmation tokens the server hands out are kept until they are used or expire
 *
 * A call to an ability that needs a confirmation is answered with a new token bound to that call (see Confirmation).
 * The client confirms by making the same call again with the token. A token is spent the first time it is presented,
 * whether or not that call matches, so it can never be tried twice. A session's tokens confirm nothing once the
 * session is gone, because a token only confirms a call made in the session it was handed out in.
 */
interface ConfirmationStoreInterface
{
    /**
     * Hand out a new token for this call, valid for ten minutes
     *
     * @param string $sessionId The session the call was made in, or ''
     * @param CallerInterface $caller
     * @param string $ability
     * @param array<string,mixed> $arguments The call's arguments, keyed by name
     * @return Confirmation
     */
    public function issue(string $sessionId, CallerInterface $caller, string $ability, array $arguments): Confirmation;

    /**
     * Spend the token, and say whether it confirms this call
     *
     * The token comes from a client, so any string is accepted: one that is unknown, already spent, expired, or
     * bound to another session, caller, ability or arguments confirms nothing.
     *
     * @param string $token
     * @param string $sessionId The session the call is made in, or ''
     * @param CallerInterface $caller
     * @param string $ability
     * @param array<string,mixed> $arguments The call's arguments, keyed by name
     * @return bool
     */
    public function consume(
        string $token,
        string $sessionId,
        CallerInterface $caller,
        string $ability,
        array $arguments
    ): bool;

    /**
     * Delete up to this many expired tokens, oldest first
     *
     * @param int $batch At least 1
     * @return int How many were deleted; less than the batch once no expired token is left
     * @throws \InvalidArgumentException When the batch is below 1
     */
    public function deleteExpired(int $batch): int;
}
