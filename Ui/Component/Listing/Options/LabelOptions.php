<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\Component\Listing\Options;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * A fixed list of values with the labels people read: the catalog grid's sources, accesses and exposures
 *
 * Declared per list as a virtual type with its "labels" (value → label, translated where the declaration says so).
 */
class LabelOptions implements OptionSourceInterface
{
    /**
     * @param array<mixed> $labels Keyed by value
     */
    public function __construct(
        private readonly array $labels = []
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
        foreach ($this->labels as $value => $label) {
            $options[] = [
                'value' => (string) $value,
                'label' => is_scalar($label) || $label instanceof \Stringable ? (string) $label : (string) $value,
            ];
        }

        return $options;
    }
}
