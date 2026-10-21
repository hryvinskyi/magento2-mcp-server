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
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Exposure\Rule\AnonymousAbilityRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\ConfirmationRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\DenyListRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\ReadOnlyModeRule;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeOverride;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemoryOverrideRepository;
use PHPUnit\Framework\TestCase;

/**
 * Each rule on its own: it answers only for what it governs and has nothing to say otherwise
 */
class RulesTest extends TestCase
{
    private const NAME = 'catalog.product_repository.save';

    public function testTheDenyListRuleDeniesAnAbilityWhoseOverrideDenies(): void
    {
        $rule = new DenyListRule(new InMemoryOverrideRepository([new FakeOverride(self::NAME, true)]));

        self::assertSame(Exposure::Denied, $rule->exposureOf($this->ability(self::NAME, AbilityAccess::Write)));
    }

    public function testTheDenyListRuleIgnoresAnOverrideThatOnlyAsksForConfirmation(): void
    {
        $rule = new DenyListRule(new InMemoryOverrideRepository([new FakeOverride(self::NAME, false, true)]));

        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Write)));
    }

    public function testTheDenyListRuleIgnoresAnAbilityWithoutAnOverride(): void
    {
        $rule = new DenyListRule(new InMemoryOverrideRepository([new FakeOverride(self::NAME, true)]));

        self::assertNull($rule->exposureOf($this->ability('catalog.product_repository.get', AbilityAccess::Read)));
    }

    public function testTheConfirmationRuleAsksForConfirmationWhereTheOverrideDoes(): void
    {
        $rule = new ConfirmationRule(new InMemoryOverrideRepository([new FakeOverride(self::NAME, false, true)]));

        self::assertSame(
            Exposure::NeedsConfirmation,
            $rule->exposureOf($this->ability(self::NAME, AbilityAccess::Write))
        );
    }

    public function testTheConfirmationRuleIgnoresAnOverrideThatOnlyDenies(): void
    {
        $rule = new ConfirmationRule(new InMemoryOverrideRepository([new FakeOverride(self::NAME, true)]));

        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Write)));
    }

    public function testReadOnlyModeBlocksWritesAndDeletes(): void
    {
        $rule = new ReadOnlyModeRule(new FakeConfig(true, true));

        self::assertSame(
            Exposure::ReadOnlyBlocked,
            $rule->exposureOf($this->ability(self::NAME, AbilityAccess::Write))
        );
        self::assertSame(
            Exposure::ReadOnlyBlocked,
            $rule->exposureOf($this->ability(self::NAME, AbilityAccess::Delete))
        );
    }

    public function testReadOnlyModeLeavesReadsAlone(): void
    {
        $rule = new ReadOnlyModeRule(new FakeConfig(true, true));

        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Read)));
    }

    public function testWithReadOnlyModeOffNothingIsBlocked(): void
    {
        $rule = new ReadOnlyModeRule(new FakeConfig(true, false));

        foreach (AbilityAccess::cases() as $access) {
            self::assertNull($rule->exposureOf($this->ability(self::NAME, $access)));
        }
    }

    public function testTheAnonymousRuleDeniesAnAbilityAnyoneMayCallWhileSuchAbilitiesAreNotExposed(): void
    {
        $rule = new AnonymousAbilityRule(new FakeConfig(true, true, false));

        foreach (AbilityAccess::cases() as $access) {
            self::assertSame(
                Exposure::Denied,
                $rule->exposureOf($this->ability(self::NAME, $access, ['anonymous']))
            );
        }
        self::assertSame(InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY, $rule->denialReason());
    }

    public function testTheAnonymousRuleLetsAnonymousAbilitiesThroughOnceTheSettingExposesThem(): void
    {
        $rule = new AnonymousAbilityRule(new FakeConfig(true, true, true));

        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Read, ['anonymous'])));
    }

    public function testTheAnonymousRuleIgnoresAnAbilityThatNeedsMoreThanAnonymous(): void
    {
        $rule = new AnonymousAbilityRule(new FakeConfig(true, true, false));

        $catalog = 'Magento_Catalog::products';
        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Read, [$catalog])));
        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Read, ['anonymous', $catalog])));
        self::assertNull($rule->exposureOf($this->ability(self::NAME, AbilityAccess::Read, [])));
    }

    /**
     * @param string $name
     * @param AbilityAccess $access
     * @param list<string> $aclResources
     * @return Ability
     */
    private function ability(string $name, AbilityAccess $access, array $aclResources = []): Ability
    {
        return new Ability($name, 'Title', 'Magento_Catalog', 'webapi', 'POST /V1/products', $access, $aclResources);
    }
}
