<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Section;

use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\Section\AbilitySections;
use Hryvinskyi\McpServer\Test\Integration\Fixture\ForgetsStoredNames;
use Hryvinskyi\McpServer\Test\Integration\Fixture\InvokesTestAbilities;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingListener;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Sections read from the store's own ACL tree, and the container's invoker denying a section switched off
 */
#[DbIsolation(true)]
class SectionsTest extends TestCase
{
    use ForgetsStoredNames;
    use InvokesTestAbilities;
    use SwitchesGraphQlSourceOff;

    private const ROLES = 'test.roles.get';
    private const SETTINGS = 'test.settings.get';
    private const SYSTEM = 'Magento_Backend::system';
    private const STORE_SETTINGS = 'Magento_Backend::stores_settings';
    private const ROLES_RESOURCE = 'Magento_User::acl_roles';
    private const SETTINGS_RESOURCE = 'Magento_Config::config';

    /**
     * @var AbilityInvoker
     */
    private AbilityInvoker $invoker;

    protected function setUp(): void
    {
        $this->invoker = $this->invokerOver(
            new FakeAbilitySource(
                'test',
                [
                    $this->readingAbility(self::ROLES, [self::ROLES_RESOURCE]),
                    $this->readingAbility(self::SETTINGS, [self::SETTINGS_RESOURCE]),
                ],
                true,
                static fn (): array => ['ok' => true]
            ),
            new RecordingListener()
        );
    }

    public function testAnAbilityBelongsToTheAreaOfItsResourceAndStoresIsSplit(): void
    {
        $sections = $this->sections();

        self::assertSame(
            [[self::SYSTEM], [self::STORE_SETTINGS], 'System', 'Stores › Settings'],
            [
                $sections->of($this->readingAbility(self::ROLES, [self::ROLES_RESOURCE])),
                $sections->of($this->readingAbility(self::SETTINGS, [self::SETTINGS_RESOURCE])),
                $sections->labelOf(self::SYSTEM),
                $sections->labelOf(self::STORE_SETTINGS),
            ]
        );
    }

    public function testASectionSwitchedOffDeniesItsAbilitiesAndNoOthers(): void
    {
        $this->switches()->switchOff(self::STORE_SETTINGS);

        $denied = $this->invoker->invoke($this->invocation(self::SETTINGS));
        $allowed = $this->invoker->invoke($this->invocation(self::ROLES));

        self::assertSame(
            [InvocationOutcome::Denied, InvocationResultInterface::DENIAL_SECTION_OFF, InvocationOutcome::Succeeded],
            [$denied->getOutcome(), $denied->getDenialReason(), $allowed->getOutcome()]
        );
        self::assertSame([self::STORE_SETTINGS], $this->switches()->off());
    }

    public function testASectionSwitchedOnAgainRunsItsAbilities(): void
    {
        $this->switches()->switchOff(self::STORE_SETTINGS);
        $this->switches()->switchOn(self::STORE_SETTINGS);

        self::assertSame(
            InvocationOutcome::Succeeded,
            $this->invoker->invoke($this->invocation(self::SETTINGS))->getOutcome()
        );
    }

    /**
     * @return AbilitySections
     */
    private function sections(): AbilitySections
    {
        $sections = Bootstrap::getObjectManager()->get(AbilitySections::class);
        self::assertInstanceOf(AbilitySections::class, $sections);

        return $sections;
    }

    /**
     * @return SectionSwitchesInterface
     */
    private function switches(): SectionSwitchesInterface
    {
        $switches = Bootstrap::getObjectManager()->get(SectionSwitchesInterface::class);
        self::assertInstanceOf(SectionSwitchesInterface::class, $switches);

        return $switches;
    }
}
