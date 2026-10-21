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
 * One confirmation token the server handed out, as an immutable value created through ConfirmationFactory
 *
 * A token confirms exactly one call: the one the server asked a confirmation for, made again in the same session, by
 * the same caller, for the same ability with the same arguments, before the token expires. Arguments are compared by
 * their fingerprint (see ArgumentsFingerprint), so the order of their keys does not matter and any other change does.
 *
 * The token proves that the client made a second, explicit call for exactly those arguments. It does not prove that a
 * person approved it.
 *
 * Times are seconds since the Unix epoch. The token is 32 lowercase hexadecimal characters.
 */
class Confirmation
{
    /**
     * The format of every token
     */
    public const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    /**
     * How long a token can be used after it was handed out, in seconds
     */
    public const LIFETIME_SECONDS = 600;

    private const FINGERPRINT_PATTERN = '/^[0-9a-f]{64}$/D';

    /**
     * @param string $token
     * @param string $sessionId The session the call was made in; '' for a call made outside any session
     * @param CallerInterface $caller The account that made the call
     * @param string $ability The name of the ability the call was for
     * @param string $argumentsFingerprint The fingerprint of the call's arguments
     * @param int $expiresAt The first moment the token no longer confirms anything
     * @throws \InvalidArgumentException When the token, the ability or the fingerprint is not valid
     */
    public function __construct(
        private readonly string $token,
        private readonly string $sessionId,
        private readonly CallerInterface $caller,
        private readonly string $ability,
        private readonly string $argumentsFingerprint,
        private readonly int $expiresAt
    ) {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('A confirmation token is 32 lowercase hexadecimal characters.');
        }

        if (trim($ability) === '') {
            throw new \InvalidArgumentException('A confirmation token is for a named ability.');
        }

        if (preg_match(self::FINGERPRINT_PATTERN, $argumentsFingerprint) !== 1) {
            throw new \InvalidArgumentException(
                'A confirmation token holds the arguments\' fingerprint as 64 lowercase hexadecimal characters.'
            );
        }
    }

    /**
     * The token the client sends back to confirm its call
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * The session the call was made in, or ''
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * The account that made the call
     *
     * @return CallerInterface
     */
    public function getCaller(): CallerInterface
    {
        return $this->caller;
    }

    /**
     * The name of the ability the call was for
     *
     * @return string
     */
    public function getAbility(): string
    {
        return $this->ability;
    }

    /**
     * The fingerprint of the call's arguments
     *
     * @return string
     */
    public function getArgumentsFingerprint(): string
    {
        return $this->argumentsFingerprint;
    }

    /**
     * The first moment the token no longer confirms anything
     *
     * @return int
     */
    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Whether the token confirms this call at that moment
     *
     * Every part must match: the session, the caller's user type and id, the ability and the arguments' fingerprint,
     * and the token must not have expired.
     *
     * @param string $sessionId
     * @param CallerInterface $caller
     * @param string $ability
     * @param string $argumentsFingerprint
     * @param int $now
     * @return bool
     */
    public function confirms(
        string $sessionId,
        CallerInterface $caller,
        string $ability,
        string $argumentsFingerprint,
        int $now
    ): bool {
        return $now < $this->expiresAt
            && hash_equals($this->sessionId, $sessionId)
            && $caller->getUserType() === $this->caller->getUserType()
            && $caller->getUserId() === $this->caller->getUserId()
            && $ability === $this->ability
            && hash_equals($this->argumentsFingerprint, $argumentsFingerprint);
    }
}
