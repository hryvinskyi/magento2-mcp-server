<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;

/**
 * Immutable invocation result, created through InvocationResultInterfaceFactory
 *
 * The constructor refuses a denied result without a reason, a reason on any other result, a failed result without a
 * message and a negative or non-finite duration. The value arrives through withValue(), which only a succeeded
 * result accepts.
 */
class InvocationResult implements InvocationResultInterface
{
    private const DENIAL_REASON_PATTERN = '/^[a-z]+(?:_[a-z]+)*$/D';

    /**
     * @var mixed
     */
    private mixed $value = null;

    /**
     * @param InvocationOutcome $outcome
     * @param float $durationMs
     * @param AbilityInterface|null $ability
     * @param string $message
     * @param string $denialReason
     * @throws \InvalidArgumentException When the values contradict the outcome
     */
    public function __construct(
        private readonly InvocationOutcome $outcome,
        private readonly float $durationMs,
        private readonly ?AbilityInterface $ability = null,
        private readonly string $message = '',
        private readonly string $denialReason = ''
    ) {
        if (!is_finite($durationMs) || $durationMs < 0) {
            throw new \InvalidArgumentException('An invocation lasts zero or more milliseconds.');
        }

        if ($outcome === InvocationOutcome::Denied && preg_match(self::DENIAL_REASON_PATTERN, $denialReason) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('A denied invocation names its reason as a lowercase code, not "%s".', $denialReason)
            );
        }

        if ($outcome !== InvocationOutcome::Denied && $denialReason !== '') {
            throw new \InvalidArgumentException(
                sprintf('Only a denied invocation has a denial reason, not a %s one.', $outcome->value)
            );
        }

        if ($outcome === InvocationOutcome::Failed && trim($message) === '') {
            throw new \InvalidArgumentException('A failed invocation says what went wrong.');
        }
    }

    /**
     * @inheritDoc
     */
    public function getOutcome(): InvocationOutcome
    {
        return $this->outcome;
    }

    /**
     * @inheritDoc
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @inheritDoc
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @inheritDoc
     */
    public function getDenialReason(): string
    {
        return $this->denialReason;
    }

    /**
     * @inheritDoc
     */
    public function getDurationMs(): float
    {
        return $this->durationMs;
    }

    /**
     * @inheritDoc
     */
    public function getAbility(): ?AbilityInterface
    {
        return $this->ability;
    }

    /**
     * @inheritDoc
     */
    public function withValue(mixed $value): InvocationResultInterface
    {
        if ($this->outcome !== InvocationOutcome::Succeeded) {
            throw new \LogicException(
                sprintf('Only a succeeded invocation carries a value, not a %s one.', $this->outcome->value)
            );
        }

        $copy = clone $this;
        $copy->value = $value;

        return $copy;
    }
}
