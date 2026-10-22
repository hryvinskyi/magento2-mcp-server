<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;

/**
 * The one line a message process writes to its standard output for the serving process to read:
 * `{"body": "<answer body or ''>", "sessionId": "<session the answer opened, or ''>", "sessionLost": <bool>}`
 *
 * - The body is the protocol's JSON answer as one JSON string, so the line holds no newline whatever the answer holds.
 * - `sessionLost` is true only when the message named a session and the server answered that it knows no such live
 *   session of the caller's (it expired, or the cap on live sessions ended it). The serving process may then open a
 *   new session and send the message again.
 * - A line holding any other key, or missing one, is no envelope.
 */
class Envelope
{
    private const BODY = 'body';
    private const SESSION_ID = 'sessionId';
    private const SESSION_LOST = 'sessionLost';

    /**
     * @param JsonEncoder $encoder
     */
    public function __construct(
        private readonly JsonEncoder $encoder
    ) {
    }

    /**
     * The envelope line, with its newline
     *
     * @param string $body
     * @param string $sessionId
     * @param bool $sessionLost Whether the session the message named is no longer a live session of the caller's
     * @return string
     * @throws \JsonException When the text cannot be written as JSON
     */
    public function encode(string $body, string $sessionId, bool $sessionLost = false): string
    {
        return $this->encoder->encode([
            self::BODY => $body,
            self::SESSION_ID => $sessionId,
            self::SESSION_LOST => $sessionLost,
        ]) . "\n";
    }

    /**
     * What an envelope line carries, or null when the text is not one envelope
     *
     * @param string $text What the message process wrote to its standard output
     * @return array{body: string, sessionId: string, sessionLost: bool}|null
     */
    public function decode(string $text): ?array
    {
        try {
            $decoded = json_decode(trim($text), true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)
            || count($decoded) !== 3
            || !is_string($decoded[self::BODY] ?? null)
            || !is_string($decoded[self::SESSION_ID] ?? null)
            || !is_bool($decoded[self::SESSION_LOST] ?? null)
        ) {
            return null;
        }

        return [
            'body' => $decoded[self::BODY],
            'sessionId' => $decoded[self::SESSION_ID],
            'sessionLost' => $decoded[self::SESSION_LOST],
        ];
    }
}
