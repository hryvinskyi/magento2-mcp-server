<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * How one invocation ended, as an immutable value
 *
 * A denied result always names why it was denied, and no other result does. Only a succeeded result carries a
 * value, and a failed one always says what went wrong.
 *
 * Create one through InvocationResultInterfaceFactory, then hand a succeeded result its value with withValue(). The
 * value is never a factory argument: in developer and default mode Magento's object manager reads an array handed
 * to a factory as DI configuration, so a nested `instance` or `argument` key in service data would be replaced by an
 * object or by deployment configuration.
 *
 * @api
 */
interface InvocationResultInterface
{
    /**
     * Denial reason: the ability is on the deny-list
     */
    public const DENIAL_DENY_LIST = 'deny_list';

    /**
     * Denial reason: read-only mode is on and the ability does not only read
     */
    public const DENIAL_READ_ONLY_MODE = 'read_only_mode';

    /**
     * Denial reason: the caller's role lacks a resource the ability needs
     */
    public const DENIAL_ACL = 'acl';

    /**
     * Denial reason: the ability takes anonymous callers, and abilities that do are not exposed to MCP clients
     */
    public const DENIAL_ANONYMOUS_ABILITY = 'anonymous_ability';

    /**
     * Denial reason: an invocation guard refused the arguments of the call
     */
    public const DENIAL_ARGUMENT_GUARD = 'argument_guard';

    /**
     * How the invocation ended
     *
     * @return InvocationOutcome
     */
    public function getOutcome(): InvocationOutcome;

    /**
     * What the ability returned; null unless the invocation succeeded
     *
     * @return mixed
     */
    public function getValue(): mixed;

    /**
     * Message for the client: what went wrong, why the call was refused, or ''
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Why the call was denied, or '' when it was not
     *
     * One of the DENIAL_* codes of this interface, or the code an exposure rule of another module reports for its
     * own restriction (a lowercase snake-case word).
     *
     * @return string
     */
    public function getDenialReason(): string;

    /**
     * How long the invocation took, in milliseconds
     *
     * @return float
     */
    public function getDurationMs(): float;

    /**
     * The ability that was invoked, or null when none was resolved
     *
     * @return AbilityInterface|null
     */
    public function getAbility(): ?AbilityInterface;

    /**
     * A copy of this succeeded result carrying the ability's return value; this one is left as it is
     *
     * @param mixed $value
     * @return InvocationResultInterface
     * @throws \LogicException When the invocation did not succeed
     */
    public function withValue(mixed $value): InvocationResultInterface;
}
