<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Model\Session\Session;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;

/**
 * Sessions kept in an array, numbered in the order they were opened, with every touch and delete recorded, and a
 * lookup failure the test can switch on
 */
class InMemorySessionStore implements SessionStoreInterface
{
    /**
     * Sessions by id; an id of digits only may come back as an integer key
     *
     * @var array<array-key, Session>
     */
    public array $sessions = [];

    /**
     * Ids of the sessions touched, in order
     *
     * @var list<string>
     */
    public array $touched = [];

    /**
     * Ids of the sessions deleted, in order
     *
     * @var list<string>
     */
    public array $deleted = [];

    /**
     * Batch sizes deleteExpired() was asked for, in order
     *
     * @var list<int>
     */
    public array $expiryBatches = [];

    /**
     * What find() throws instead of answering, as a store whose database is gone would; null to answer
     *
     * @var \Throwable|null
     */
    public ?\Throwable $findFailure = null;

    /**
     * @param list<int> $expiredCounts What each deleteExpired() call answers, in order; 0 once they run out
     */
    public function __construct(
        private array $expiredCounts = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function create(CallerInterface $caller, ClientInterface $client, string $protocolVersion): Session
    {
        $id = sprintf('%032x', count($this->sessions) + 1);

        return $this->sessions[$id] = new Session($id, $caller, $client, $protocolVersion, 1000, 1000);
    }

    /**
     * Open a session under a chosen id, as another test step or another caller would have
     *
     * @param string $id
     * @param CallerInterface $caller
     * @param ClientInterface $client
     * @param string $protocolVersion
     * @return Session
     */
    public function add(
        string $id,
        CallerInterface $caller,
        ClientInterface $client,
        string $protocolVersion = '2025-06-18'
    ): Session {
        return $this->sessions[$id] = new Session($id, $caller, $client, $protocolVersion, 1000, 1000);
    }

    /**
     * @inheritDoc
     */
    public function find(string $id): ?Session
    {
        if ($this->findFailure !== null) {
            throw $this->findFailure;
        }

        return $this->sessions[$id] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function touch(Session $session): void
    {
        $this->touched[] = $session->getId();
    }

    /**
     * @inheritDoc
     */
    public function delete(string $id): void
    {
        $this->deleted[] = $id;
        unset($this->sessions[$id]);
    }

    /**
     * @inheritDoc
     */
    public function deleteExpired(int $batch): int
    {
        $this->expiryBatches[] = $batch;

        return array_shift($this->expiredCounts) ?? 0;
    }
}
