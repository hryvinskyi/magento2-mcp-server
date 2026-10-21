<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Cron;

use Hryvinskyi\McpServer\Model\Confirmation\ConfirmationStoreInterface;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;

/**
 * Removes expired MCP sessions and expired confirmation tokens, one batch at a time, until a batch comes back short
 *
 * Batches keep each delete small, so the cleanup never holds a long lock on a table that requests are reading.
 */
class CleanExpiredSessions
{
    /**
     * @param SessionStoreInterface $sessions
     * @param ConfirmationStoreInterface $confirmations
     * @param int $batchSize Rows deleted per statement, at least 1
     */
    public function __construct(
        private readonly SessionStoreInterface $sessions,
        private readonly ConfirmationStoreInterface $confirmations,
        private readonly int $batchSize = 1000
    ) {
    }

    /**
     * Cron entry point
     *
     * @return void
     */
    public function execute(): void
    {
        do {
            $deleted = $this->sessions->deleteExpired($this->batchSize);
        } while ($deleted >= $this->batchSize);

        do {
            $deleted = $this->confirmations->deleteExpired($this->batchSize);
        } while ($deleted >= $this->batchSize);
    }
}
