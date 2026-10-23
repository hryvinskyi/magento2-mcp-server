<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\ResultFilterInterface;

/**
 * A result filter that does what the test hands it
 */
class CallbackResultFilter implements ResultFilterInterface
{
    /**
     * @param \Closure(AbilityInterface, mixed): mixed $filter
     */
    public function __construct(
        private readonly \Closure $filter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function filter(AbilityInterface $ability, mixed $value): mixed
    {
        return ($this->filter)($ability, $value);
    }
}
