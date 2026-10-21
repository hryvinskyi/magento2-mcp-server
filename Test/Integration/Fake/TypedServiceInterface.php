<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

/**
 * A service whose contract takes any text, for a test of a call its implementation refuses by type
 */
interface TypedServiceInterface
{
    /**
     * Double a number given as text
     *
     * @param string $value
     * @return mixed
     */
    public function double($value);
}
