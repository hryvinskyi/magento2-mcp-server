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
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The sections card of the MCP Server page: every section with its abilities and its switch
 *
 * @phpstan-import-type SectionRow from CatalogSections
 */
class SectionsOverview implements ArgumentInterface
{
    private const MANAGE_RESOURCE = 'Hryvinskyi_McpServer::manage';
    private const ON_ROUTE = 'hryvinskyi_mcp/section/on';
    private const OFF_ROUTE = 'hryvinskyi_mcp/section/off';
    private const CARD_COMPONENT = 'Hryvinskyi_McpServer/js/sections-card';
    private const CATALOG_SOURCE = 'hryvinskyi_mcp_ability_listing.hryvinskyi_mcp_ability_listing_data_source';

    /**
     * @param CatalogSections $sections
     * @param AuthorizationInterface $authorization
     * @param BackendUrl $backendUrl
     * @param JsonHexTag $json
     */
    public function __construct(
        private readonly CatalogSections $sections,
        private readonly AuthorizationInterface $authorization,
        private readonly BackendUrl $backendUrl,
        private readonly JsonHexTag $json
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
        return $this->backendUrl->getUrl($section['off'] ? self::ON_ROUTE : self::OFF_ROUTE);
    }

    /**
     * The sections card's x-magento-init configuration, or an empty string when the admin may not switch sections
     *
     * It lets the switches change a section without reloading the page. The script needs both switch addresses, to
     * point a form at the opposite switch once its section changed, the catalog's data source, to reload the catalog
     * after a switch, and the wording it writes onto a row.
     *
     * @param string $selector The card the script mounts on
     * @return string
     */
    public function mountConfig(string $selector): string
    {
        if (!$this->canSwitch()) {
            return '';
        }

        return (string) $this->json->serialize([
            $selector => [self::CARD_COMPONENT => [
                'onUrl' => $this->backendUrl->getUrl(self::ON_ROUTE),
                'offUrl' => $this->backendUrl->getUrl(self::OFF_ROUTE),
                'catalogSource' => self::CATALOG_SOURCE,
                'wording' => [
                    'on' => (string) __('On'),
                    'off' => (string) __('Off'),
                    'switchOn' => (string) __('Switch on'),
                    'switchOff' => (string) __('Switch off'),
                    'failed' => (string) __('The section could not be switched. Reload the page and try again.'),
                ],
            ]],
        ]);
    }
}
