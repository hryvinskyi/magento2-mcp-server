<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\Component\Listing\Options;

use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Every category (module) the catalog's abilities belong to, in alphabetical order
 */
class CategoryOptions implements OptionSourceInterface
{
    /**
     * @param AbilityRows $rows
     */
    public function __construct(
        private readonly AbilityRows $rows
    ) {
    }

    /**
     * The options, each with its value and label
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray()
    {
        $categories = array_unique(array_column($this->rows->all(), 'category'));
        sort($categories, SORT_STRING | SORT_FLAG_CASE);

        return array_map(
            static fn (string $category): array => ['value' => $category, 'label' => $category],
            $categories
        );
    }
}
