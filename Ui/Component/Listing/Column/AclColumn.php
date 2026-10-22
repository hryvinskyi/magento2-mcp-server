<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * The ACL resources an ability needs, one tag each; an ability open to anyone says so
 *
 * The resources are checked against the caller's role at call time: a resource the role lacks makes the call
 * denied, it does not hide the ability.
 */
class AclColumn extends Column
{
    private const ANONYMOUS = 'anonymous';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param array<mixed> $components
     * @param array<mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Put the resources of each row into its cell
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
            $resources = is_array($row) ? ($row['aclResources'] ?? null) : null;
            if (is_array($row) && is_array($resources)) {
                $row[$name] = $this->cell(array_values(array_filter($resources, 'is_string')));
                $items[$index] = $row;
            }
        }
        $data['items'] = $items;
        $dataSource['data'] = $data;

        return $dataSource;
    }

    /**
     * The tags of the resources
     *
     * @param list<string> $resources
     * @return string
     */
    private function cell(array $resources): string
    {
        if ($resources === [self::ANONYMOUS]) {
            return '<span class="mcp-acl mcp-acl--open">' . $this->escaper->escapeHtml(__('none · open to anyone'))
                . '</span>';
        }

        $html = '';
        foreach ($resources as $resource) {
            $html .= '<span class="mcp-acl">' . $this->escaper->escapeHtml($resource) . '</span>';
        }

        return $html;
    }
}
