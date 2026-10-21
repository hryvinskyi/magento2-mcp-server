<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * One call of one ability by one caller through one transport, as an immutable value
 *
 * Create one through InvocationInterfaceFactory, then hand it the client's arguments with withArguments(). The
 * arguments are never a factory argument: in developer and default mode Magento's object manager reads an array
 * handed to a factory as DI configuration, so a nested `instance` key in client data would make it build that class
 * and an `argument` key would read deployment configuration into the call.
 *
 * @api
 */
interface InvocationInterface
{
    /**
     * Name of the ability the client asked for, as it asked; it may name no ability at all
     *
     * @return string
     */
    public function getAbilityName(): string;

    /**
     * Arguments the client passed, keyed by parameter name
     *
     * @return array<string, mixed>
     */
    public function getArguments(): array;

    /**
     * Whether the client confirmed the call
     *
     * @return bool
     */
    public function isConfirmed(): bool;

    /**
     * The account the call runs as
     *
     * @return CallerInterface
     */
    public function getCaller(): CallerInterface;

    /**
     * The MCP client that sent the call
     *
     * @return ClientInterface
     */
    public function getClient(): ClientInterface;

    /**
     * Code of the transport the call came through, a lowercase word such as `http`
     *
     * @return string
     */
    public function getTransport(): string;

    /**
     * MCP session the call belongs to, or '' outside a session
     *
     * @return string
     */
    public function getSessionId(): string;

    /**
     * A copy of this invocation carrying these arguments; this one is left as it is
     *
     * @param array<string,mixed> $arguments
     * @return InvocationInterface
     */
    public function withArguments(array $arguments): InvocationInterface;
}
