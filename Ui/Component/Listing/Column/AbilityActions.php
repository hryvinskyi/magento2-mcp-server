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
use Hryvinskyi\McpServer\Ui\Component\ManageMassAction;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * The one change a row offers: put the ability on the deny-list, or take it off the one the merchant manages
 *
 * Offered only to an admin who may manage the abilities' exposure, and sent as a POST with the form key.
 *
 * @phpstan-import-type AbilityRow from AbilityRows
 */
class AbilityActions extends Column
{
    public const ROUTE = 'hryvinskyi_mcp/server/exposure';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param ExposureStatus $status
     * @param UrlInterface $url
     * @param AuthorizationInterface $authorization
     * @param array<mixed> $components
     * @param array<mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly ExposureStatus $status,
        private readonly UrlInterface $url,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Give each row the change it offers
     *
     * @param array<mixed> $dataSource
     * @return array<mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $data = $dataSource['data'] ?? null;
        $items = is_array($data) ? ($data['items'] ?? null) : null;
        $name = $this->getData('name');
        if (!is_array($data)
            || !is_array($items)
            || !is_string($name)
            || !$this->authorization->isAllowed(ManageMassAction::MANAGE_RESOURCE)
        ) {
            return $dataSource;
        }

        foreach ($items as $index => $row) {
            if (!is_array($row) || !isset($row['name'], $row['exposure'], $row['reason'])) {
                continue;
            }
            /** @var AbilityRow $row */
            $change = $this->status->of($row)['change'];
            if ($change !== null) {
                $row[$name] = [$change => $this->action($change, $row['name'])];
                $items[$index] = $row;
            }
        }
        $data['items'] = $items;
        $dataSource['data'] = $data;

        return $dataSource;
    }

    /**
     * The action of one change on one ability
     *
     * @param string $change
     * @param string $ability
     * @return array<string, mixed>
     */
    private function action(string $change, string $ability): array
    {
        $deny = $change === ExposureStatus::DENY;

        return [
            'href' => $this->url->getUrl(self::ROUTE, ['change' => $change, 'name' => $ability]),
            'label' => $deny ? __('Add to deny-list') : __('Remove from deny-list'),
            'post' => true,
            'confirm' => [
                'title' => $deny ? __('Add to deny-list?') : __('Remove from deny-list?'),
                'message' => $deny
                    ? __('No client will be able to call %1.', $ability)
                    : __('Clients will be able to call %1 again, as its other settings allow.', $ability),
            ],
        ];
    }
}
