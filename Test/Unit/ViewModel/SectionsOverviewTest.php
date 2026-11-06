<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\ViewModel;

use Hryvinskyi\McpServer\Model\Admin\CatalogSections;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAuthorization;
use Hryvinskyi\McpServer\ViewModel\SectionsOverview;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only an admin who may manage the server can switch sections, and only then does the sections card get its script,
 * with both switch addresses, the catalog to reload and the wording it writes onto a row
 */
class SectionsOverviewTest extends TestCase
{
    private const MANAGE = 'Hryvinskyi_McpServer::manage';
    private const SELECTOR = '[data-role="mcp-sections"]';

    public function testWithoutTheManageRightNoSectionCanBeSwitched(): void
    {
        self::assertFalse($this->overview([])->canSwitch());
    }

    public function testWithoutTheManageRightTheCardGetsNoScript(): void
    {
        self::assertSame('', $this->overview([])->mountConfig(self::SELECTOR));
    }

    public function testASwitchPostsToTheOppositeOfTheSectionsState(): void
    {
        $overview = $this->overview([self::MANAGE]);

        self::assertSame(
            'https://admin.example/hryvinskyi_mcp/section/off',
            $overview->switchUrl($this->section(false))
        );
        self::assertSame('https://admin.example/hryvinskyi_mcp/section/on', $overview->switchUrl($this->section(true)));
    }

    public function testTheCardsScriptIsMountedWithBothSwitchesTheCatalogAndTheWording(): void
    {
        $config = json_decode($this->overview([self::MANAGE])->mountConfig(self::SELECTOR), true);

        self::assertSame([
            self::SELECTOR => [
                'Hryvinskyi_McpServer/js/sections-card' => [
                    'onUrl' => 'https://admin.example/hryvinskyi_mcp/section/on',
                    'offUrl' => 'https://admin.example/hryvinskyi_mcp/section/off',
                    'catalogSource' => 'hryvinskyi_mcp_ability_listing.hryvinskyi_mcp_ability_listing_data_source',
                    'wording' => [
                        'on' => 'On',
                        'off' => 'Off',
                        'switchOn' => 'Switch on',
                        'switchOff' => 'Switch off',
                        'failed' => 'The section could not be switched. Reload the page and try again.',
                    ],
                ],
            ],
        ], $config);
    }

    public function testTheConfigurationCannotEndTheScriptElementItIsPrintedIn(): void
    {
        $config = $this->overview([self::MANAGE])->mountConfig('</script><script>alert(1)</script>');

        self::assertStringNotContainsString('</script>', $config);
    }

    /**
     * A section row in the given state
     *
     * @param bool $off
     * @return array{code: string, label: string, abilities: int, exposed: int, modules: list<string>, off: bool}
     */
    private function section(bool $off): array
    {
        return [
            'code' => 'Magento_Backend::system',
            'label' => 'System',
            'abilities' => 8,
            'exposed' => $off ? 0 : 7,
            'modules' => ['Magento_Backend'],
            'off' => $off,
        ];
    }

    /**
     * The view model for an admin allowed the given resources, with a URL builder that writes the route
     *
     * @param list<string> $allowed
     * @return SectionsOverview
     */
    private function overview(array $allowed): SectionsOverview
    {
        /** @var BackendUrl&MockObject $backendUrl */
        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturnCallback(
            static fn (?string $routePath = null): string => 'https://admin.example/' . $routePath
        );

        return new SectionsOverview(
            $this->createMock(CatalogSections::class),
            new FakeAuthorization($allowed),
            $backendUrl,
            new JsonHexTag()
        );
    }
}
