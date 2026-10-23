<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\ViewModel;

use Hryvinskyi\McpServer\Model\Admin\CatalogSections;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The sections card of the MCP Server page: every section with its abilities and its switch
 *
 * @phpstan-import-type SectionRow from CatalogSections
 */
class SectionsOverview implements ArgumentInterface
{
    private const MANAGE_RESOURCE = 'Hryvinskyi_McpServer::manage';

    /**
     * @param CatalogSections $sections
     * @param AuthorizationInterface $authorization
     * @param BackendUrl $backendUrl
     */
    public function __construct(
        private readonly CatalogSections $sections,
        private readonly AuthorizationInterface $authorization,
        private readonly BackendUrl $backendUrl
    ) {
    }

    /**
     * Every section, by label
     *
     * @return list<SectionRow>
     */
    public function sections(): array
    {
        return $this->sections->all();
    }

    /**
     * Whether the admin may switch sections
     *
     * @return bool
     */
    public function canSwitch(): bool
    {
        return $this->authorization->isAllowed(self::MANAGE_RESOURCE);
    }

    /**
     * Where the switch of a section posts to
     *
     * @param SectionRow $section
     * @return string
     */
    public function switchUrl(array $section): string
    {
        return $this->backendUrl->getUrl($section['off'] ? 'hryvinskyi_mcp/section/on' : 'hryvinskyi_mcp/section/off');
    }
}
