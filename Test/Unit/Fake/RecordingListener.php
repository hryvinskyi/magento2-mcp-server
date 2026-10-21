<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\InvocationListenerInterface;

/**
 * An invocation listener that remembers what it heard, in order
 */
class RecordingListener implements InvocationListenerInterface
{
    /**
     * Events heard, as "started" or "finished"
     *
     * @var list<string>
     */
    public array $events = [];

    /**
     * Results heard with "finished"
     *
     * @var list<InvocationResultInterface>
     */
    public array $results = [];

    /**
     * Invocations heard with "started"
     *
     * @var list<InvocationInterface>
     */
    public array $started = [];

    /**
     * @inheritDoc
     */
    public function started(InvocationInterface $invocation): void
    {
        $this->events[] = 'started';
        $this->started[] = $invocation;
    }

    /**
     * @inheritDoc
     */
    public function finished(InvocationInterface $invocation, InvocationResultInterface $result): void
    {
        $this->events[] = 'finished';
        $this->results[] = $result;
    }
}
