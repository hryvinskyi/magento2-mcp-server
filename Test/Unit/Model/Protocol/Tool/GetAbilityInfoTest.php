<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Tool\GetAbilityInfo;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedExposurePolicy;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\TestCase;

/**
 * `get_ability_info` over a fake catalog: everything about one ability, whatever the caller's ACL
 */
class GetAbilityInfoTest extends TestCase
{
    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
    }

    public function testAnAbilityIsDescribedWithItsSchemaExposureAndWhetherTheCallerMayCallIt(): void
    {
        $result = $this->tool(Exposure::ReadOnlyBlocked, false)->call(
            ['name' => 'catalog.product_repository.save'],
            $this->kit->callContext()
        );

        self::assertFalse($result['isError']);
        self::assertEquals(
            [
                'name' => 'catalog.product_repository.save',
                'title' => 'Save a product',
                'description' => '',
                'category' => 'Magento_Catalog',
                'source' => 'webapi',
                'access' => 'write',
                'aclResources' => ['Magento_Catalog::products', 'Magento_Catalog::edit'],
                'exposure' => 'read_only_blocked',
                'allowedForYou' => false,
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'description' => 'webapi'],
            ],
            $result['structuredContent']
        );
    }

    public function testAnUnknownNameIsAToolErrorPointingAtDiscovery(): void
    {
        $result = $this->tool()->call(['name' => 'catalog.nothing.here'], $this->kit->callContext());

        self::assertTrue($result['isError']);
        self::assertArrayNotHasKey('structuredContent', $result);
        self::assertSame(
            [['type' => 'text', 'text' => 'No ability is named "catalog.nothing.here". '
                . 'Find ability names with discover_abilities.']],
            $result['content']
        );
    }

    public function testAMissingNameIsRefused(): void
    {
        $this->expectException(InvalidToolArgumentException::class);
        $this->expectExceptionMessage('"name"');

        $this->tool()->call([], $this->kit->callContext());
    }

    /**
     * @param Exposure $exposure
     * @param bool $allowed
     * @return GetAbilityInfo
     */
    private function tool(Exposure $exposure = Exposure::Exposed, bool $allowed = true): GetAbilityInfo
    {
        return new GetAbilityInfo(
            $this->kit->catalog($allowed),
            new FixedExposurePolicy($exposure),
            $this->kit->argumentReader(),
            $this->kit->toolResults()
        );
    }
}
