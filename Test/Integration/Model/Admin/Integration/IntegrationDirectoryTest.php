<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationDirectoryInterface;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationVerdict;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationVerdictsInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\CreatesIntegrations;
use Magento\Authorization\Model\ResourceModel\Role\Collection as RoleCollection;
use Magento\Authorization\Model\Acl\Role\Group;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Integration\Model\Integration;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The readiness of real integrations, judged against the ACL records and tokens Magento stores for them — the same
 * ones the MCP endpoint checks a caller against — through the real container
 */
#[DbIsolation(true)]
class IntegrationDirectoryTest extends TestCase
{
    use CreatesIntegrations;

    private const ACCESS = 'Hryvinskyi_McpServer::access';

    public function testAnIntegrationAllowedOnlyTheEndpointIsReady(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);

        self::assertSame(IntegrationReadiness::Ready, $this->readinessOf($integration['id']));
    }

    public function testTheVerdictOnAReadyIntegrationCarriesTheTokenMagentoStoredForItsConsumer(): void
    {
        $integration = $this->createIntegration('mcp-test verdict token', [self::ACCESS]);

        $verdict = $this->verdicts()->verdictOf($integration['id']);

        self::assertInstanceOf(IntegrationVerdict::class, $verdict);
        self::assertSame(IntegrationReadiness::Ready, $verdict->getReadiness());
        self::assertTrue(hash_equals($integration['token'], (string) $verdict->getUsableToken()));
    }

    public function testTheVerdictOnAnIntegrationWhoseTokenWasRevokedCarriesNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test verdict revoked', [self::ACCESS]);
        $this->revokeTokenOf($integration['consumerId']);

        $verdict = $this->verdicts()->verdictOf($integration['id']);

        self::assertInstanceOf(IntegrationVerdict::class, $verdict);
        self::assertSame(IntegrationReadiness::NoToken, $verdict->getReadiness());
        self::assertNull($verdict->getUsableToken());
    }

    public function testAnIntegrationWithAllResourcesIsReady(): void
    {
        $integration = $this->createIntegration('mcp-test all resources', null);

        self::assertSame(IntegrationReadiness::Ready, $this->readinessOf($integration['id']));
    }

    public function testAnIntegrationWhoseResourcesLeaveOutTheEndpointHasNoEndpointAccess(): void
    {
        $integration = $this->createIntegration(
            'mcp-test stores only',
            ['Magento_Backend::stores', 'Magento_Backend::stores_settings']
        );

        self::assertSame(IntegrationReadiness::NoEndpointAccess, $this->readinessOf($integration['id']));
    }

    public function testAnIntegrationWhoseTokenWasRevokedHasNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test revoked', [self::ACCESS]);
        $this->revokeTokenOf($integration['consumerId']);

        self::assertSame(IntegrationReadiness::NoToken, $this->readinessOf($integration['id']));
    }

    public function testAnIntegrationWithoutATokenHasNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test no token', [self::ACCESS], withToken: false);

        self::assertSame(IntegrationReadiness::NoToken, $this->readinessOf($integration['id']));
    }

    public function testACreatedIntegrationThatWasNeverActivatedIsInactive(): void
    {
        $integration = $this->createIntegration(
            'mcp-test inactive',
            [self::ACCESS],
            Integration::STATUS_INACTIVE,
            false
        );

        self::assertSame(IntegrationReadiness::Inactive, $this->readinessOf($integration['id']));
    }

    public function testAnUnknownIntegrationHasNoReadiness(): void
    {
        $integration = $this->createIntegration('mcp-test known', [self::ACCESS]);

        self::assertNull($this->verdicts()->verdictOf($integration['id'] + 1000));
    }

    public function testTheListingNamesEveryIntegrationWithItsReadinessSortedByNameWhateverTheCase(): void
    {
        $ready = $this->createIntegration('mcp-test a ready', [self::ACCESS]);
        $all = $this->createIntegration('MCP-TEST b all resources', null);
        $stores = $this->createIntegration('mcp-test c stores', ['Magento_Backend::stores']);
        $revoked = $this->createIntegration('Mcp-Test d revoked', [self::ACCESS]);
        $this->revokeTokenOf($revoked['consumerId']);
        $inactive = $this->createIntegration('mcp-test e inactive', [self::ACCESS], Integration::STATUS_INACTIVE);
        $ours = [$ready['id'], $all['id'], $stores['id'], $revoked['id'], $inactive['id']];

        $rows = array_values(array_filter(
            $this->directory()->all(),
            static fn (array $row): bool => in_array($row['id'], $ours, true)
        ));

        self::assertSame(
            [
                ['id' => $ready['id'], 'name' => 'mcp-test a ready', 'readiness' => IntegrationReadiness::Ready],
                ['id' => $all['id'], 'name' => 'MCP-TEST b all resources', 'readiness' => IntegrationReadiness::Ready],
                [
                    'id' => $stores['id'],
                    'name' => 'mcp-test c stores',
                    'readiness' => IntegrationReadiness::NoEndpointAccess,
                ],
                ['id' => $revoked['id'], 'name' => 'Mcp-Test d revoked', 'readiness' => IntegrationReadiness::NoToken],
                [
                    'id' => $inactive['id'],
                    'name' => 'mcp-test e inactive',
                    'readiness' => IntegrationReadiness::Inactive,
                ],
            ],
            $rows
        );
    }

    /**
     * Reading the integrations' ACL rebuilds the request's ACL for each integration's role; without giving it back,
     * every later check of the request would deny the admin
     *
     * @return void
     */
    public function testTheAdminsAclStillHoldsAfterTheListing(): void
    {
        $this->createIntegration('mcp-test stores only', ['Magento_Backend::stores']);
        $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);
        $administrators = $this->administratorsRoleId();
        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'Before');

        $this->directory()->all();

        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'After');
    }

    public function testTheAdminsAclStillHoldsAfterOneIntegrationsReadiness(): void
    {
        $integration = $this->createIntegration('mcp-test stores only', ['Magento_Backend::stores']);
        $administrators = $this->administratorsRoleId();
        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'Before');

        $this->verdicts()->verdictOf($integration['id']);

        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'After');
    }

    /**
     * The id of the Administrators role, which every resource is allowed
     *
     * @return string
     */
    private function administratorsRoleId(): string
    {
        $roles = Bootstrap::getObjectManager()->create(RoleCollection::class);
        self::assertInstanceOf(RoleCollection::class, $roles);
        $role = $roles->addFieldToFilter('role_name', 'Administrators')
            ->addFieldToFilter('role_type', Group::ROLE_TYPE)
            ->getFirstItem();
        $id = $role->getData('role_id');
        self::assertIsNumeric($id, 'The Administrators role exists');

        return (string) $id;
    }

    /**
     * @return PolicyInterface
     */
    private function policy(): PolicyInterface
    {
        $policy = Bootstrap::getObjectManager()->get(PolicyInterface::class);
        self::assertInstanceOf(PolicyInterface::class, $policy);

        return $policy;
    }

    /**
     * The readiness of one integration, as the verdict taken through the real container states it
     *
     * @param int $integrationId
     * @return IntegrationReadiness|null
     */
    private function readinessOf(int $integrationId): ?IntegrationReadiness
    {
        return $this->verdicts()->verdictOf($integrationId)?->getReadiness();
    }

    /**
     * @return IntegrationVerdictsInterface
     */
    private function verdicts(): IntegrationVerdictsInterface
    {
        $verdicts = Bootstrap::getObjectManager()->get(IntegrationVerdictsInterface::class);
        self::assertInstanceOf(IntegrationVerdictsInterface::class, $verdicts);

        return $verdicts;
    }

    /**
     * @return IntegrationDirectoryInterface
     */
    private function directory(): IntegrationDirectoryInterface
    {
        $directory = Bootstrap::getObjectManager()->get(IntegrationDirectoryInterface::class);
        self::assertInstanceOf(IntegrationDirectoryInterface::class, $directory);

        return $directory;
    }
}
