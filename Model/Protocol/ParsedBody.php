<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * The messages of one request body, as an immutable value created through ParsedBodyFactory
 *
 * A single message is a list of one that is not a batch. The messages arrive through withMessages(), which returns a
 * copy and refuses an empty list and, outside a batch, more than one message.
 */
class ParsedBody
{
    /**
     * @var list<Message>
     */
    private array $messages = [];

    /**
     * @param bool $batch Whether the body was a JSON array of messages
     */
    public function __construct(
        private readonly bool $batch
    ) {
    }

    /**
     * Whether the body was a JSON array of messages, to be answered with a JSON array
     *
     * @return bool
     */
    public function isBatch(): bool
    {
        return $this->batch;
    }

    /**
     * The messages, in the order the client sent them
     *
     * @return list<Message>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * A copy of this body carrying these messages; this one is left as it is
     *
     * @param list<Message> $messages
     * @return ParsedBody
     * @throws \InvalidArgumentException When the list is empty, or holds more than one message outside a batch
     */
    public function withMessages(array $messages): ParsedBody
    {
        if ($messages === []) {
            throw new \InvalidArgumentException('A body holds at least one message.');
        }

        if (!$this->batch && count($messages) > 1) {
            throw new \InvalidArgumentException('A body that is not a batch holds exactly one message.');
        }

        $copy = clone $this;
        $copy->messages = $messages;

        return $copy;
    }
}
