<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Exposure\Rule;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Exposure\Rule\SectionOffRule;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemorySectionSwitches;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\TestCase;

/**
 * Abilities of a section switched off are denied with the reason `section_off`
 */
class SectionOffRuleTest extends TestCase
{
    public function testAnAbilityIsDeniedWhenOneOfItsSectionsIsOff(): void
    {
        $rule = $this->rule(['Magento_InventoryApi::inventory']);

        self::assertSame(
            [Exposure::Denied, null, 'section_off'],
            [
                $rule->exposureOf($this->ability(['Magento_User::acl_users', 'Magento_InventoryApi::source'])),
                $rule->exposureOf($this->ability(['Magento_User::acl_users'])),
                $rule->denialReason(),
            ]
        );
    }

    public function testNothingIsOffSoTheRuleHasNoSay(): void
    {
        self::assertNull($this->rule([])->exposureOf($this->ability(['Magento_User::acl_users'])));
    }

    /**
     * @param list<string> $off
     * @return SectionOffRule
     */
    private function rule(array $off): SectionOffRule
    {
        return new SectionOffRule((new ProtocolKit($this))->sections(), new InMemorySectionSwitches($off));
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
