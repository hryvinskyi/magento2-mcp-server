<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;

/**
 * Immutable invocation, created through InvocationInterfaceFactory
 *
 * The ability name is kept as the client sent it, since telling a client that it named no ability is the invoker's
 * answer, not a construction error. The transport is a lowercase code. The arguments arrive through withArguments(),
 * which returns a copy, so no client data passes through the object manager's argument handling.
 */
class Invocation implements InvocationInterface
{
    private const TRANSPORT_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /**
     * @var array<string, mixed>
     */
    private array $arguments = [];

    /**
     * @param string $abilityName
     * @param CallerInterface $caller
     * @param ClientInterface $client
     * @param string $transport
     * @param string $sessionId
     * @param bool $confirmed
     * @throws \InvalidArgumentException When the transport is not a lowercase code
     */
    public function __construct(
        private readonly string $abilityName,
        private readonly CallerInterface $caller,
        private readonly ClientInterface $client,
        private readonly string $transport,
        private readonly string $sessionId = '',
        private readonly bool $confirmed = false
    ) {
        if (preg_match(self::TRANSPORT_PATTERN, $transport) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('The transport "%s" is not a lowercase code.', $transport)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getAbilityName(): string
    {
        return $this->abilityName;
    }

    /**
     * @inheritDoc
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @inheritDoc
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * @inheritDoc
     */
    public function getCaller(): CallerInterface
    {
        return $this->caller;
    }

    /**
     * @inheritDoc
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * @inheritDoc
     */
    public function getTransport(): string
    {
        return $this->transport;
    }

    /**
     * @inheritDoc
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @inheritDoc
     */
    public function withArguments(array $arguments): InvocationInterface
    {
        $copy = clone $this;
        $copy->arguments = $arguments;

        return $copy;
    }
}
