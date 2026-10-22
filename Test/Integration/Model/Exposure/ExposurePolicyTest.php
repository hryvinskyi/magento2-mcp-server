<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Exposure;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Model\AbilityOverrideRepository;
use Hryvinskyi\McpServer\Model\Config;
use Hryvinskyi\McpServer\Model\Data\ExposureDecision;
use Hryvinskyi\McpServer\Model\Exposure\ExposurePolicy;
use Hryvinskyi\McpServer\Model\Exposure\Rule\BuiltInDenyListRule;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The exposure policy as the container wires it: the stored overrides, the built-in deny-list, read-only mode and
 * abilities anyone may call, with the reason each denial reports
 */
#[DbIsolation(true)]
class ExposurePolicyTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use SwitchesGraphQlSourceOff;

    private const WRITE = 'catalog.product_repository.save';
    private const READ = 'catalog.product_repository.get_list';
    private const TOKEN_ISSUER = 'integration.admin_token_service.create_admin_access_token';
    private const ANONYMOUS = 'directory.country_information_acquirer.get_countries_info';

    /**
     * The shared repository keeps what it read; a previous test's rows were rolled back under it
     *
     * @return void
     */
    protected function setUp(): void
    {
        $repository = Bootstrap::getObjectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepository::class, $repository);
        $repository->_resetState();
    }

    public function testThePreferenceIsThePolicy(): void
    {
        self::assertInstanceOf(ExposurePolicy::class, $this->policy());
    }

    public function testAfterInstallReadOnlyModeBlocksAWriteAndLeavesAReadExposed(): void
    {
        self::assertSame(
            Exposure::ReadOnlyBlocked,
            $this->policy()->exposureOf($this->ability(self::WRITE, AbilityAccess::Write))
        );
        self::assertSame(
            Exposure::Exposed,
            $this->policy()->exposureOf($this->ability(self::READ, AbilityAccess::Read))
        );
    }

    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testWithReadOnlyModeOffAWriteIsExposed(): void
    {
        self::assertSame(
            Exposure::Exposed,
            $this->policy()->exposureOf($this->ability(self::WRITE, AbilityAccess::Write))
        );
    }

    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testAStoredConfirmationIsAsked(): void
    {
        $this->storeOverride(self::WRITE, false, true);

        self::assertSame(
            Exposure::NeedsConfirmation,
            $this->policy()->exposureOf($this->ability(self::WRITE, AbilityAccess::Write))
        );
    }

    public function testReadOnlyModeOutranksAStoredConfirmation(): void
    {
        $this->storeOverride(self::WRITE, false, true);

        self::assertSame(
            Exposure::ReadOnlyBlocked,
            $this->policy()->exposureOf($this->ability(self::WRITE, AbilityAccess::Write))
        );
    }

    public function testAStoredDenialOutranksEverything(): void
    {
        $this->storeOverride(self::READ, true, true);

        self::assertSame(
            Exposure::Denied,
            $this->policy()->exposureOf($this->ability(self::READ, AbilityAccess::Read))
        );
    }

    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testTheBuiltInDenyListDeniesATokenIssuerWithoutAnyOverride(): void
    {
        $rule = Bootstrap::getObjectManager()->get(BuiltInDenyListRule::class);
        self::assertInstanceOf(BuiltInDenyListRule::class, $rule);
        self::assertTrue($rule->matches(self::TOKEN_ISSUER));
        self::assertFalse($rule->matches(self::WRITE));

        self::assertSame(
            Exposure::Denied,
            $this->policy()->exposureOf($this->ability(self::TOKEN_ISSUER, AbilityAccess::Write))
        );
    }

    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testAnOverrideCannotLiftTheBuiltInDenyList(): void
    {
        $this->storeOverride(self::TOKEN_ISSUER, false, true);

        self::assertSame(
            Exposure::Denied,
            $this->policy()->exposureOf($this->ability(self::TOKEN_ISSUER, AbilityAccess::Write))
        );
    }

    public function testAfterInstallAnAbilityAnyoneMayCallIsDeniedForThatReason(): void
    {
        $decision = $this->policy()->decisionOf(
            $this->ability(self::ANONYMOUS, AbilityAccess::Read, ['anonymous'])
        );

        self::assertInstanceOf(ExposureDecision::class, $decision);
        self::assertSame(Exposure::Denied, $decision->getExposure());
        self::assertSame(InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY, $decision->getDenialReason());
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testOnceExposedAnAbilityAnyoneMayCallFollowsTheOtherRules(): void
    {
        $read = $this->policy()->decisionOf($this->ability(self::ANONYMOUS, AbilityAccess::Read, ['anonymous']));
        $write = $this->policy()->decisionOf($this->ability(self::WRITE, AbilityAccess::Write, ['anonymous']));

        self::assertSame(Exposure::Exposed, $read->getExposure());
        self::assertSame('', $read->getDenialReason());
        self::assertSame(Exposure::ReadOnlyBlocked, $write->getExposure());
        self::assertSame(InvocationResultInterface::DENIAL_READ_ONLY_MODE, $write->getDenialReason());
    }

    public function testAStoredDenialOfAnAbilityAnyoneMayCallReadsAsTheDenyList(): void
    {
        $this->storeOverride(self::ANONYMOUS, true, false);

        $decision = $this->policy()->decisionOf(
            $this->ability(self::ANONYMOUS, AbilityAccess::Read, ['anonymous'])
        );

        self::assertSame(Exposure::Denied, $decision->getExposure());
        self::assertSame(InvocationResultInterface::DENIAL_DENY_LIST, $decision->getDenialReason());
    }

    public function testAnAbilityThatNeedsMoreThanAnonymousIsNotTouchedByTheSetting(): void
    {
        $decision = $this->policy()->decisionOf(
            $this->ability(self::READ, AbilityAccess::Read, ['anonymous', 'Magento_Catalog::products'])
        );

        self::assertSame(Exposure::Exposed, $decision->getExposure());
    }

    /**
     * @return ExposurePolicyInterface
     */
    private function policy(): ExposurePolicyInterface
    {
        $policy = Bootstrap::getObjectManager()->get(ExposurePolicyInterface::class);
        self::assertInstanceOf(ExposurePolicyInterface::class, $policy);

        return $policy;
    }

    /**
     * @param string $ability
     * @param bool $denied
     * @param bool $needsConfirmation
     * @return void
     */
    private function storeOverride(string $ability, bool $denied, bool $needsConfirmation): void
    {
        $override = Bootstrap::getObjectManager()->create(AbilityOverrideInterface::class);
        self::assertInstanceOf(AbilityOverrideInterface::class, $override);
        $repository = Bootstrap::getObjectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepositoryInterface::class, $repository);

        $repository->save(
            $override->setAbility($ability)->setDenied($denied)->setNeedsConfirmation($needsConfirmation)
        );
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
        $factory = Bootstrap::getObjectManager()->get(AbilityInterfaceFactory::class);
        self::assertInstanceOf(AbilityInterfaceFactory::class, $factory);

        return $factory->create([
            'name' => $name,
            'title' => 'Ability',
            'category' => 'Magento_Catalog',
            'source' => 'webapi',
            'sourceReference' => 'POST /V1/products',
            'access' => $access,
            'aclResources' => $aclResources,
        ]);
    }
}
