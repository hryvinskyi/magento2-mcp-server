<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Exposure;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterface;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\ExposureRuleInterface;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\ExposureDecision;
use Hryvinskyi\McpServer\Model\Exposure\ExposurePolicy;
use Hryvinskyi\McpServer\Model\Exposure\Rule\AnonymousAbilityRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\BuiltInDenyListRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\ConfirmationRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\DenyListRule;
use Hryvinskyi\McpServer\Model\Exposure\Rule\ReadOnlyModeRule;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeOverride;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedAnswerRule;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedReasonRule;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemoryOverrideRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The precedence of the exposure rules: deny-list (stored, built-in, or an anonymous ability while such abilities are
 * not exposed), then read-only mode for abilities that do not only read, then a required confirmation, else exposed.
 * It must hold in any order of the rules, a rule that exposes must not lift another rule's answer, and the denial
 * reason must not depend on the order either.
 */
class ExposurePolicyTest extends TestCase
{
    private const BUILT_IN_DENIED_NAME = 'integration.admin_token_service.create_admin_access_token';
    private const ORDINARY_NAME = 'catalog.product_repository.get_list';

    /**
     * Every combination of the settings, under every order of the rules
     *
     * @return iterable<string, array{bool, bool, bool, AbilityAccess, bool, bool, bool, bool}>
     */
    public static function combinations(): iterable
    {
        foreach ([false, true] as $denied) {
            foreach ([false, true] as $builtInDenied) {
                foreach ([false, true] as $readOnlyMode) {
                    foreach ([false, true] as $anonymousDenied) {
                        yield from self::casesFor($denied, $builtInDenied, $readOnlyMode, $anonymousDenied);
                    }
                }
            }
        }
    }

    /**
     * Every access, confirmation and order of the rules under one set of deny-list and mode settings
     *
     * @param bool $denied
     * @param bool $builtInDenied
     * @param bool $readOnlyMode
     * @param bool $anonymousDenied Whether the ability takes anonymous callers while such abilities are not exposed
     * @return iterable<string, array{bool, bool, bool, AbilityAccess, bool, bool, bool, bool}>
     */
    private static function casesFor(
        bool $denied,
        bool $builtInDenied,
        bool $readOnlyMode,
        bool $anonymousDenied
    ): iterable {
        foreach (AbilityAccess::cases() as $access) {
            foreach ([false, true] as $confirm) {
                foreach ([false, true] as $reversed) {
                    foreach ([false, true] as $exposingRuleFirst) {
                        $label = sprintf(
                            'override deny %s, built-in deny %s, read-only mode %s, anonymous %s, %s ability, '
                            . 'override confirm %s, rules %s%s',
                            $denied ? 'on' : 'off',
                            $builtInDenied ? 'hit' : 'miss',
                            $readOnlyMode ? 'on' : 'off',
                            $anonymousDenied ? 'denied' : 'not denied',
                            $access->value,
                            $confirm ? 'on' : 'off',
                            $reversed ? 'reversed' : 'in the wired order',
                            $exposingRuleFirst ? ', after a rule answering exposed' : ''
                        );

                        yield $label => [
                            $denied,
                            $builtInDenied,
                            $readOnlyMode,
                            $access,
                            $confirm,
                            $reversed,
                            $exposingRuleFirst,
                            $anonymousDenied,
                        ];
                    }
                }
            }
        }
    }

    #[DataProvider('combinations')]
    public function testThePrecedenceAndTheReasonHoldInEveryOrderOfTheRules(
        bool $denied,
        bool $builtInDenied,
        bool $readOnlyMode,
        AbilityAccess $access,
        bool $confirm,
        bool $reversed,
        bool $exposingRuleFirst,
        bool $anonymousDenied
    ): void {
        $name = $builtInDenied ? self::BUILT_IN_DENIED_NAME : self::ORDINARY_NAME;
        $rules = $this->rules($readOnlyMode, [new FakeOverride($name, $denied, $confirm)]);
        if ($reversed) {
            $rules = array_reverse($rules, true);
        }
        if ($exposingRuleFirst) {
            $rules = ['exposing' => new FixedAnswerRule(Exposure::Exposed)] + $rules;
        }

        $decision = $this->policy($rules)->decisionOf(
            $this->ability($name, $access, $anonymousDenied ? ['anonymous'] : ['Magento_Catalog::products'])
        );

        [$exposure, $reason] = $this->expected(
            $denied || $builtInDenied,
            $anonymousDenied,
            $readOnlyMode && !$access->isReadOnly(),
            $confirm
        );
        self::assertSame($exposure, $decision->getExposure());
        self::assertSame($reason, $decision->getDenialReason());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function orders(): array
    {
        return ['in order' => [false], 'reversed' => [true]];
    }

    #[DataProvider('orders')]
    public function testAmongEquallyStrictRulesTheDefaultReasonWinsElseTheAlphabeticallyFirst(bool $reversed): void
    {
        $default = ['zeta' => new FixedReasonRule(Exposure::Denied, 'zeta_rule'), 'plain' => new FixedAnswerRule(
            Exposure::Denied
        )];
        $named = [
            'zeta' => new FixedReasonRule(Exposure::Denied, 'zeta_rule'),
            'alpha' => new FixedReasonRule(Exposure::Denied, 'alpha_rule'),
        ];
        $blocked = [
            'own' => new FixedReasonRule(Exposure::ReadOnlyBlocked, 'quiet_hours'),
            'plain' => new FixedAnswerRule(Exposure::ReadOnlyBlocked),
        ];
        $ability = $this->ability(self::ORDINARY_NAME, AbilityAccess::Write);

        foreach ([[$default, 'deny_list'], [$named, 'alpha_rule'], [$blocked, 'read_only_mode']] as [$rules, $reason]) {
            $decision = $this->policy($reversed ? array_reverse($rules, true) : $rules)->decisionOf($ability);
            self::assertSame($reason, $decision->getDenialReason());
        }
    }

    public function testAStricterAnswerReplacesTheReasonsOfMilderOnes(): void
    {
        $policy = $this->policy([
            'blocked' => new FixedReasonRule(Exposure::ReadOnlyBlocked, 'quiet_hours'),
            'denied' => new FixedReasonRule(Exposure::Denied, 'zeta_rule'),
            'confirm' => new FixedReasonRule(Exposure::NeedsConfirmation, 'ignored_reason'),
        ]);

        $decision = $policy->decisionOf($this->ability(self::ORDINARY_NAME, AbilityAccess::Write));

        self::assertSame(Exposure::Denied, $decision->getExposure());
        self::assertSame('zeta_rule', $decision->getDenialReason());
    }

    public function testAnExposureThatLetsTheCallThroughHasNoReason(): void
    {
        $policy = $this->policy(['confirm' => new FixedReasonRule(Exposure::NeedsConfirmation, 'some_reason')]);

        $decision = $policy->decisionOf($this->ability(self::ORDINARY_NAME, AbilityAccess::Write));

        self::assertSame(Exposure::NeedsConfirmation, $decision->getExposure());
        self::assertSame('', $decision->getDenialReason());
    }

    public function testTheDenyListBeatsReadOnlyModeAndConfirmation(): void
    {
        $policy = $this->policy(
            $this->rules(true, [new FakeOverride(self::ORDINARY_NAME, true, true)])
        );

        self::assertSame(
            Exposure::Denied,
            $policy->exposureOf($this->ability(self::ORDINARY_NAME, AbilityAccess::Write))
        );
    }

    public function testReadOnlyModeBeatsConfirmationForAWrite(): void
    {
        $policy = $this->policy(
            $this->rules(true, [new FakeOverride(self::ORDINARY_NAME, false, true)])
        );

        self::assertSame(
            Exposure::ReadOnlyBlocked,
            $policy->exposureOf($this->ability(self::ORDINARY_NAME, AbilityAccess::Delete))
        );
    }

    public function testReadOnlyModeLeavesAReadAbilityToTheConfirmation(): void
    {
        $policy = $this->policy(
            $this->rules(true, [new FakeOverride(self::ORDINARY_NAME, false, true)])
        );

        self::assertSame(
            Exposure::NeedsConfirmation,
            $policy->exposureOf($this->ability(self::ORDINARY_NAME, AbilityAccess::Read))
        );
    }

    public function testTheBuiltInListDeniesWhateverTheOverridesSay(): void
    {
        $policy = $this->policy($this->rules(false, []));

        self::assertSame(
            Exposure::Denied,
            $policy->exposureOf($this->ability(self::BUILT_IN_DENIED_NAME, AbilityAccess::Write))
        );
    }

    public function testAnAbilityNoRuleRestrictsIsExposed(): void
    {
        $ability = $this->ability(self::ORDINARY_NAME, AbilityAccess::Write);

        self::assertSame(Exposure::Exposed, $this->policy([])->exposureOf($ability));
        self::assertSame(Exposure::Exposed, $this->policy($this->rules(false, []))->exposureOf($ability));
    }

    public function testARuleAnsweringExposedCannotLiftADenial(): void
    {
        $rules = $this->rules(false, [new FakeOverride(self::ORDINARY_NAME, true)]);

        $policy = $this->policy(['exposing' => new FixedAnswerRule(Exposure::Exposed)] + $rules);

        self::assertSame(
            Exposure::Denied,
            $policy->exposureOf($this->ability(self::ORDINARY_NAME, AbilityAccess::Read))
        );
    }

    /**
     * The policy over these rules, with the generated decision factory
     *
     * @param array<string, ExposureRuleInterface> $rules
     * @return ExposurePolicy
     */
    private function policy(array $rules): ExposurePolicy
    {
        return new ExposurePolicy(
            new ExposureDecisionInterfaceFactory(
                new ConstructingObjectManager([ExposureDecisionInterface::class => ExposureDecision::class])
            ),
            $rules
        );
    }

    /**
     * The five rules, keyed and ordered as di.xml wires them; anonymous abilities are not exposed
     *
     * @param bool $readOnlyMode
     * @param list<FakeOverride> $overrides
     * @return array<string, ExposureRuleInterface>
     */
    private function rules(bool $readOnlyMode, array $overrides): array
    {
        $repository = new InMemoryOverrideRepository($overrides);
        $config = new FakeConfig(true, $readOnlyMode);

        return [
            'denyList' => new DenyListRule($repository),
            'builtInDenyList' => new BuiltInDenyListRule(['token_issuers_and_integrations' => 'integration.*']),
            'readOnlyMode' => new ReadOnlyModeRule($config),
            'anonymousAbility' => new AnonymousAbilityRule($config),
            'confirmation' => new ConfirmationRule($repository),
        ];
    }

    /**
     * The precedence written as a ladder, the way the rules describe it, with the reason it reports
     *
     * @param bool $deniedByList
     * @param bool $anonymousDenied
     * @param bool $blockedByReadOnlyMode
     * @param bool $confirm
     * @return array{Exposure, string}
     */
    private function expected(
        bool $deniedByList,
        bool $anonymousDenied,
        bool $blockedByReadOnlyMode,
        bool $confirm
    ): array {
        if ($deniedByList) {
            return [Exposure::Denied, InvocationResultInterface::DENIAL_DENY_LIST];
        }
        if ($anonymousDenied) {
            return [Exposure::Denied, InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY];
        }
        if ($blockedByReadOnlyMode) {
            return [Exposure::ReadOnlyBlocked, InvocationResultInterface::DENIAL_READ_ONLY_MODE];
        }

        return [$confirm ? Exposure::NeedsConfirmation : Exposure::Exposed, ''];
    }

    /**
     * @param string $name
     * @param AbilityAccess $access
     * @param list<string> $aclResources
     * @return AbilityInterface
     */
    private function ability(
        string $name,
        AbilityAccess $access,
        array $aclResources = ['Magento_Catalog::products']
    ): AbilityInterface {
        return new Ability($name, 'Title', 'Magento_Catalog', 'webapi', 'GET /V1/products', $access, $aclResources);
    }
}
