<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Model\Confirmation\ArgumentsFingerprint;
use Hryvinskyi\McpServer\Model\Confirmation\Confirmation;
use Hryvinskyi\McpServer\Model\Confirmation\ConfirmationStoreInterface;
use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;

/**
 * Confirmation tokens kept in an array, numbered in the order they were handed out, spent on first presentation the
 * way the database store spends them, with the real fingerprint and matching rules
 */
class InMemoryConfirmationStore implements ConfirmationStoreInterface
{
    /**
     * Tokens not spent yet, by token
     *
     * @var array<string, Confirmation>
     */
    public array $tokens = [];

    /**
     * Every token handed out, in order
     *
     * @var list<Confirmation>
     */
    public array $issued = [];

    /**
     * Every token presented, in order
     *
     * @var list<string>
     */
    public array $presented = [];

    /**
     * Batch sizes deleteExpired() was asked for, in order
     *
     * @var list<int>
     */
    public array $expiryBatches = [];

    /**
     * The time of day the tokens are checked at
     *
     * @var int
     */
    public int $now = 1000;

    /**
     * @var ArgumentsFingerprint
     */
    private ArgumentsFingerprint $fingerprint;

    /**
     * @param list<int> $expiredCounts What each deleteExpired() call answers, in order; 0 once they run out
     */
    public function __construct(
        private array $expiredCounts = []
    ) {
        $this->fingerprint = new ArgumentsFingerprint(new JsonEncoder());
    }

    /**
     * @inheritDoc
     */
    public function issue(string $sessionId, CallerInterface $caller, string $ability, array $arguments): Confirmation
    {
        $confirmation = new Confirmation(
            sprintf('%032x', count($this->issued) + 1),
            $sessionId,
            $caller,
            $ability,
            $this->fingerprint->of($arguments),
            $this->now + Confirmation::LIFETIME_SECONDS
        );
        $this->issued[] = $confirmation;
        $this->tokens[$confirmation->getToken()] = $confirmation;

        return $confirmation;
    }

    /**
     * @inheritDoc
     */
    public function consume(
        string $token,
        string $sessionId,
        CallerInterface $caller,
        string $ability,
        array $arguments
    ): bool {
        $this->presented[] = $token;
        $confirmation = $this->tokens[$token] ?? null;
        unset($this->tokens[$token]);

        return $confirmation !== null
            && $confirmation->confirms($sessionId, $caller, $ability, $this->fingerprint->of($arguments), $this->now);
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
