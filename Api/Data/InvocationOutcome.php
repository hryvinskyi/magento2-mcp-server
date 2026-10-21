<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * How one invocation of an ability ended
 *
 * - succeeded: the ability ran and returned a value;
 * - failed: the ability ran, or was asked to run, and threw;
 * - denied: the exposure policy or the caller's ACL refused the call, so nothing ran;
 * - needs_confirmation: the ability needs a confirmed call and this one was not, so nothing ran.
 *
 * @api
 */
enum InvocationOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Denied = 'denied';
    case NeedsConfirmation = 'needs_confirmation';
}
