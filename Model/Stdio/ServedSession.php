<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

/**
 * What one run of the serving loop knows about its MCP session, created through ServedSessionFactory for each run
 *
 * - The session id the message processes announced last, or '' before any.
 * - The `initialize` line that opened it, and the `notifications/initialized` line the client sent after it, kept so
 *   that a session the server lost (expired, or ended by the cap on live sessions) can be opened again the way the
 *   client opened it. A new `initialize` forgets the earlier notification.
 */
class ServedSession
{
    /**
     * The session id messages are sent with, or ''
     *
     * @var string
     */
    private string $id = '';

    /**
     * The `initialize` line that opened the session, or ''
     *
     * @var string
     */
    private string $initializeLine = '';

    /**
     * The `notifications/initialized` line sent after the session was opened, or ''
     *
     * @var string
     */
    private string $initializedLine = '';

    /**
     * The session id messages are sent with, or '' when none is known
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The client's `initialize` line opened this session
     *
     * @param string $sessionId
     * @param string $initializeLine
     * @return void
     */
    public function opened(string $sessionId, string $initializeLine): void
    {
        $this->id = $sessionId;
        $this->initializeLine = $initializeLine;
        $this->initializedLine = '';
    }

    /**
     * A message process announced this session for a line that did not open it
     *
     * @param string $sessionId
     * @return void
     */
    public function adopt(string $sessionId): void
    {
        $this->id = $sessionId;
    }

    /**
     * The client confirmed the session with this `notifications/initialized` line
     *
     * @param string $initializedLine
     * @return void
     */
    public function confirmed(string $initializedLine): void
    {
        $this->initializedLine = $initializedLine;
    }

    /**
     * The `initialize` line that opened the session, or '' when no line did
     *
     * @return string
     */
    public function getInitializeLine(): string
    {
        return $this->initializeLine;
    }

    /**
     * The `notifications/initialized` line sent after the session was opened, or '' when none was
     *
     * @return string
     */
    public function getInitializedLine(): string
    {
        return $this->initializedLine;
    }
}
