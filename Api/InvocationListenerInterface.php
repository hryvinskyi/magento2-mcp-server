<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;

/**
 * Hears of every invocation: the module's one extension point for recording calls
 *
 * A listener that throws never changes how the invocation ends: its exception is logged and the next listener is
 * called.
 *
 * @api
 */
interface InvocationListenerInterface
{
    /**
     * The invocation is about to be checked and run
     *
     * @param InvocationInterface $invocation
     * @return void
     */
    public function started(InvocationInterface $invocation): void;

    /**
     * The invocation ended, whatever the outcome, denials included
     *
     * @param InvocationInterface $invocation
     * @param InvocationResultInterface $result
     * @return void
     */
    public function finished(InvocationInterface $invocation, InvocationResultInterface $result): void;
}
