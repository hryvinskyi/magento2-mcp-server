<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Cron;

use Hryvinskyi\McpServer\Cron\CleanExpiredSessions;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemoryConfirmationStore;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

/**
 * The cleanup asks each store for batches until one comes back short
 */
class CleanExpiredSessionsTest extends TestCase
{
    public function testBatchesAreDeletedUntilOneComesBackShort(): void
    {
        $sessions = new InMemorySessionStore([3, 3, 1, 3]);
        $confirmations = new InMemoryConfirmationStore([3, 2, 3]);

        (new CleanExpiredSessions($sessions, $confirmations, 3))->execute();

        self::assertSame([3, 3, 3], $sessions->expiryBatches);
        self::assertSame([3, 3], $confirmations->expiryBatches);
    }

    public function testNothingExpiredMeansOneBatchEach(): void
    {
        $sessions = new InMemorySessionStore();
        $confirmations = new InMemoryConfirmationStore();

        (new CleanExpiredSessions($sessions, $confirmations, 1000))->execute();

        self::assertSame([1000], $sessions->expiryBatches);
        self::assertSame([1000], $confirmations->expiryBatches);
    }
}
