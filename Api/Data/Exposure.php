<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * Whether a client may call an ability
 *
 * - exposed: the ability can be called;
 * - needs_confirmation: the ability can be called once the client confirms the call;
 * - read_only_blocked: read-only mode is on and the ability does not only read;
 * - denied: the ability is on the deny-list, or another rule keeps it from every client.
 *
 * Exposure says nothing about the caller's ACL: an exposed ability is still refused to a caller whose role lacks
 * its resources.
 *
 * @api
 */
enum Exposure: string
{
    case Exposed = 'exposed';
    case NeedsConfirmation = 'needs_confirmation';
    case ReadOnlyBlocked = 'read_only_blocked';
    case Denied = 'denied';

    /**
     * Whether a client may call the ability, with a confirmation where one is needed
     *
     * @return bool
     */
    public function allowsCall(): bool
    {
        return $this === self::Exposed || $this === self::NeedsConfirmation;
    }

    /**
     * The denial reason a call refused with this exposure reports when the rule that decided it names none
     *
     * `deny_list` for a denied ability and `read_only_mode` for one read-only mode blocks; '' for an exposure that
     * lets the call through.
     *
     * @return string
     */
    public function defaultDenialReason(): string
    {
        return match ($this) {
            self::Denied => InvocationResultInterface::DENIAL_DENY_LIST,
            self::ReadOnlyBlocked => InvocationResultInterface::DENIAL_READ_ONLY_MODE,
            self::Exposed, self::NeedsConfirmation => '',
        };
    }

    /**
     * How much the exposure restricts a call: the higher, the stricter
     *
     * The order is the precedence of the exposure policy: a deny-list answer beats read-only mode, read-only mode
     * beats a required confirmation, and a required confirmation beats an exposed ability.
     *
     * @return int
     */
    public function restrictiveness(): int
    {
        return match ($this) {
            self::Exposed => 0,
            self::NeedsConfirmation => 1,
            self::ReadOnlyBlocked => 2,
            self::Denied => 3,
        };
    }

    /**
     * The stricter of this exposure and the other one; this one when both are equally strict
     *
     * @param Exposure $other
     * @return Exposure
     */
    public function stricter(Exposure $other): Exposure
    {
        return $other->restrictiveness() > $this->restrictiveness() ? $other : $this;
    }
}
