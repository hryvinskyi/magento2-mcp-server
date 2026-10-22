<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\Component\Listing\Column;

use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Admin\ExposureStatus;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * An ability's exposure as a dot and a label, with the line that says why under it
 *
 * The cell is HTML built here from escaped text only.
 *
 * @phpstan-import-type AbilityRow from AbilityRows
 * @phpstan-import-type Status from ExposureStatus
 */
class ExposureColumn extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param ExposureStatus $status
     * @param Escaper $escaper
     * @param array<mixed> $components
     * @param array<mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly ExposureStatus $status,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Put the status of each row into its cell
     *
     * @param array<mixed> $dataSource
     * @return array<mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $data = $dataSource['data'] ?? null;
        $items = is_array($data) ? ($data['items'] ?? null) : null;
        $name = $this->getData('name');
        if (!is_array($data) || !is_array($items) || !is_string($name)) {
            return $dataSource;
        }

        foreach ($items as $index => $row) {
            if (is_array($row) && isset($row['exposure'], $row['reason'])) {
                /** @var AbilityRow $row */
                $row[$name] = $this->cell($this->status->of($row));
                $items[$index] = $row;
            }
        }
        $data['items'] = $items;
        $dataSource['data'] = $data;

        return $dataSource;
    }

    /**
     * The cell of one status
     *
     * @param Status $status
     * @return string
     */
    private function cell(array $status): string
    {
        $html = '<span class="mcp-status mcp-status--' . $this->escaper->escapeHtmlAttr($status['tone']) . '">'
            . '<span class="mcp-status__dot" aria-hidden="true"></span>'
            . $this->escaper->escapeHtml($status['label']) . '</span>';

        return $status['note'] === ''
            ? $html
            : $html . '<div class="mcp-status__note">' . $this->escaper->escapeHtml($status['note']) . '</div>';
    }
}
