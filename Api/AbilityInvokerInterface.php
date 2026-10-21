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
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Calls an ability for a client, after the exposure, ACL and confirmation checks
 *
 * A refused call changes nothing: every check runs before the source is asked to invoke. Denials and failures are
 * results, never exceptions, and every invocation listener hears of each call that names a known ability.
 *
 * @api
 */
interface AbilityInvokerInterface
{
    /**
     * Run the invocation and say how it ended
     *
     * @param InvocationInterface $invocation
     * @return InvocationResultInterface
     * @throws NoSuchEntityException When the invocation names no known ability
     */
    public function invoke(InvocationInterface $invocation): InvocationResultInterface;
}
