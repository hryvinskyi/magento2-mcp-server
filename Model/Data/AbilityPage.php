<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;

/**
 * Immutable page of abilities, created through AbilityPageInterfaceFactory
 *
 * A page never holds more abilities than matched in all.
 */
class AbilityPage implements AbilityPageInterface
{
    /**
     * @param int $total
     * @param list<AbilityInterface> $items
     * @throws \InvalidArgumentException When the page holds more abilities than its total
     */
    public function __construct(
        private readonly int $total,
        private readonly array $items = []
    ) {
        if ($total < count($items)) {
            throw new \InvalidArgumentException(
                sprintf('A page of %d abilities cannot come from %d matches.', count($items), $total)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @inheritDoc
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
