<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Section;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Section\AbilitySections;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sections read from the ACL tree
 */
class AbilitySectionsTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, list<string>, list<string>}>
     */
    public static function abilities(): array
    {
        return [
            'a resource deep in an area belongs to the area' => [
                ['Magento_User::locks'],
                ['Magento_Backend::system'],
                ['System'],
            ],
            'the area node itself' => [['Acme_Shop::sales'], ['Acme_Shop::sales'], ['Sales']],
            'a split area gives its children sections of their own' => [
                ['Acme_Stock::source', 'Magento_Config::config'],
                ['Acme_Stock::inventory', 'Magento_Backend::stores_settings'],
                ['Stores › Inventory', 'Stores › Settings'],
            ],
            'two resources of one area make one section' => [
                ['Magento_User::acl_users', 'Magento_User::locks'],
                ['Magento_Backend::system'],
                ['System'],
            ],
            'a resource outside the tree that has a section of its own' => [
                ['anonymous'],
                ['anonymous'],
                ['Open to anyone'],
            ],
            'a resource the admin tree does not hold' => [['Magento_Webapi::other_tree'], ['other'], ['Other']],
            'an unknown resource' => [['Vendor_Gone::thing'], ['other'], ['Other']],
            'no resource at all' => [[], ['other'], ['Other']],
        ];
    }

    /**
     * @param list<string> $resources
     * @param list<string> $sections
     * @param list<string> $labels
     */
    #[DataProvider('abilities')]
    public function testAnAbilityBelongsToTheSectionsOfItsResources(
        array $resources,
        array $sections,
        array $labels
    ): void {
        $abilitySections = (new ProtocolKit($this))->sections();

        $found = $abilitySections->of($this->ability($resources));

        self::assertSame($sections, $found);
        self::assertSame($labels, array_map([$abilitySections, 'labelOf'], $found));
    }

    public function testTheSplitAreaItselfIsStillASection(): void
    {
        $sections = (new ProtocolKit($this))->sections();

        self::assertSame(['Magento_Backend::stores'], $sections->of($this->ability(['Magento_Backend::stores'])));
        self::assertSame('Stores', $sections->labelOf('Magento_Backend::stores'));
    }

    /**
     * @param list<string> $resources
     * @return Ability
     */
    private function ability(array $resources): Ability
    {
        return new Ability(
            'test.thing.get',
            'A thing',
            'Test',
            'webapi',
            'GET /V1/things',
            AbilityAccess::Read,
            $resources
        );
    }
}
