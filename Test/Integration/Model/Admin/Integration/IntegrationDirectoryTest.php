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

        self::assertSame(IntegrationReadiness::Ready, $this->directory()->readinessOf($integration['id']));
    }

    public function testAnIntegrationWithAllResourcesIsReady(): void
    {
        $integration = $this->createIntegration('mcp-test all resources', null);

        self::assertSame(IntegrationReadiness::Ready, $this->directory()->readinessOf($integration['id']));
    }

    public function testAnIntegrationWhoseResourcesLeaveOutTheEndpointHasNoEndpointAccess(): void
    {
        $integration = $this->createIntegration(
            'mcp-test catalog only',
            ['Magento_Catalog::catalog', 'Magento_Catalog::products']
        );

        self::assertSame(IntegrationReadiness::NoEndpointAccess, $this->directory()->readinessOf($integration['id']));
    }

    public function testAnIntegrationWhoseTokenWasRevokedHasNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test revoked', [self::ACCESS]);
        $this->revokeTokenOf($integration['consumerId']);

        self::assertSame(IntegrationReadiness::NoToken, $this->directory()->readinessOf($integration['id']));
    }

    public function testAnIntegrationWithoutATokenHasNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test no token', [self::ACCESS], withToken: false);

        self::assertSame(IntegrationReadiness::NoToken, $this->directory()->readinessOf($integration['id']));
    }

    public function testACreatedIntegrationThatWasNeverActivatedIsInactive(): void
    {
        $integration = $this->createIntegration(
            'mcp-test inactive',
            [self::ACCESS],
            Integration::STATUS_INACTIVE,
            false
        );

        self::assertSame(IntegrationReadiness::Inactive, $this->directory()->readinessOf($integration['id']));
    }

    public function testAnUnknownIntegrationHasNoReadiness(): void
    {
        $integration = $this->createIntegration('mcp-test known', [self::ACCESS]);

        self::assertNull($this->directory()->readinessOf($integration['id'] + 1000));
    }

    public function testTheListingNamesEveryIntegrationWithItsReadinessSortedByNameWhateverTheCase(): void
    {
        $ready = $this->createIntegration('mcp-test a ready', [self::ACCESS]);
        $all = $this->createIntegration('MCP-TEST b all resources', null);
        $catalog = $this->createIntegration('mcp-test c catalog', ['Magento_Catalog::catalog']);
        $revoked = $this->createIntegration('Mcp-Test d revoked', [self::ACCESS]);
        $this->revokeTokenOf($revoked['consumerId']);
        $inactive = $this->createIntegration('mcp-test e inactive', [self::ACCESS], Integration::STATUS_INACTIVE);
        $ours = [$ready['id'], $all['id'], $catalog['id'], $revoked['id'], $inactive['id']];

        $rows = array_values(array_filter(
            $this->directory()->all(),
            static fn (array $row): bool => in_array($row['id'], $ours, true)
        ));

        self::assertSame(
            [
                ['id' => $ready['id'], 'name' => 'mcp-test a ready', 'readiness' => IntegrationReadiness::Ready],
                ['id' => $all['id'], 'name' => 'MCP-TEST b all resources', 'readiness' => IntegrationReadiness::Ready],
                [
                    'id' => $catalog['id'],
                    'name' => 'mcp-test c catalog',
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
        $this->createIntegration('mcp-test catalog only', ['Magento_Catalog::catalog']);
        $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);
        $administrators = $this->administratorsRoleId();
        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'Before');

        $this->directory()->all();

        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'After');
    }

    public function testTheAdminsAclStillHoldsAfterOneIntegrationsReadiness(): void
    {
        $integration = $this->createIntegration('mcp-test catalog only', ['Magento_Catalog::catalog']);
        $administrators = $this->administratorsRoleId();
        self::assertTrue($this->policy()->isAllowed($administrators, 'Hryvinskyi_McpServer::manage'), 'Before');

        $this->directory()->readinessOf($integration['id']);

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
     * @return IntegrationDirectoryInterface
     */
    private function directory(): IntegrationDirectoryInterface
    {
        $directory = Bootstrap::getObjectManager()->get(IntegrationDirectoryInterface::class);
        self::assertInstanceOf(IntegrationDirectoryInterface::class, $directory);

        return $directory;
    }
}
