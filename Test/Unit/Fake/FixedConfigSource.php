<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\App\Config\ConfigSourceInterface;

/**
 * A configuration source holding fixed data, keyed by scope the way Magento's sources key it, and counting its reads
 */
class FixedConfigSource implements ConfigSourceInterface
{
    /**
     * How many times the source was read
     *
     * @var int
     */
    public int $reads = 0;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly array $data
    ) {
    }

    /**
     * Everything the source holds; the path is ignored, as the code under test reads the whole source
     *
     * @param string $path
     * @return array<string, mixed>
     */
    public function get($path = '')
    {
        $this->reads++;

        return $this->data;
    }
}
