<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;

/**
 * Changes the value a succeeded call returns before it leaves the store
 *
 * The invoker passes every value through each filter of its di.xml pool `resultFilters`, in order, before the
 * invocation listeners hear of the result and before the client gets it: what a listener records is what left the
 * store. A filter that throws ends the call as failed, so an unfiltered value never goes out.
 *
 * @api
 */
interface ResultFilterInterface
{
    /**
     * The value to hand on in place of the one the ability returned
     *
     * @param AbilityInterface $ability The ability that returned the value
     * @param mixed $value What the ability, or the filter before this one, returned
     * @return mixed
     */
    public function filter(AbilityInterface $ability, mixed $value): mixed;
}
