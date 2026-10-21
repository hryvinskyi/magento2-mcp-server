<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * One parsed JSON-RPC message, as an immutable value created through MessageFactory
 *
 * The constructor refuses a message whose parts contradict its kind: a request has a method and a string or number
 * id, a notification a method and no id, a client response no method, and an invalid message no method, no id and a
 * reason. The params arrive through withParams(), which returns a copy, so no client data passes through the object
 * manager's argument handling.
 */
class Message
{
    /**
     * @var array<string, mixed>
     */
    private array $params = [];

    /**
     * @param MessageKind $kind
     * @param string $method The method of a request or a notification, '' otherwise
     * @param int|string|null $id The id of a request or a client response, null otherwise
     * @param string $error Why an invalid message is invalid, '' otherwise
     * @throws \InvalidArgumentException When the parts contradict the kind
     */
    public function __construct(
        private readonly MessageKind $kind,
        private readonly string $method = '',
        private readonly int|string|null $id = null,
        private readonly string $error = ''
    ) {
        $named = $kind === MessageKind::Request || $kind === MessageKind::Notification;
        if ($named !== ($method !== '')) {
            throw new \InvalidArgumentException(
                sprintf('A %s message %s a method.', $kind->value, $named ? 'needs' : 'has no')
            );
        }

        if ($kind === MessageKind::Request && $id === null) {
            throw new \InvalidArgumentException('A request has an id.');
        }

        if (($kind === MessageKind::Notification || $kind === MessageKind::Invalid) && $id !== null) {
            throw new \InvalidArgumentException(sprintf('A %s message has no id.', $kind->value));
        }

        if (($kind === MessageKind::Invalid) !== (trim($error) !== '')) {
            throw new \InvalidArgumentException('Only an invalid message, and every one of them, says why.');
        }
    }

    /**
     * The kind of message
     *
     * @return MessageKind
     */
    public function getKind(): MessageKind
    {
        return $this->kind;
    }

    /**
     * The method of a request or a notification, '' otherwise
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * The id a response must echo; null for a notification and an invalid message
     *
     * @return int|string|null
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * Why an invalid message is invalid, '' otherwise
     *
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * The params the client sent, keyed by name
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * A copy of this message carrying these params; this one is left as it is
     *
     * @param array<string,mixed> $params
     * @return Message
     */
    public function withParams(array $params): Message
    {
        $copy = clone $this;
        $copy->params = $params;

        return $copy;
    }
}
