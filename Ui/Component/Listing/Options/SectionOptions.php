<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\Component\Listing\Options;

use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Section\AbilitySections;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Every section the catalog's abilities belong to, by label
 */
class SectionOptions implements OptionSourceInterface
{
    /**
     * @param AbilityRows $rows
     * @param AbilitySections $sections
     */
    public function __construct(
        private readonly AbilityRows $rows,
        private readonly AbilitySections $sections
    ) {
    }

    /**
     * The options, each with its value and label
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray()
    {
        $options = [];
        foreach (array_unique(array_merge(...array_column($this->rows->all(), 'sections'))) as $section) {
            $options[] = ['value' => $section, 'label' => $this->sections->labelOf($section)];
        }
        usort($options, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return $options;
    }
}
