<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Test\Integration\Fake\FixedUserContext;
use Magento\Authorization\Model\Acl\Role\Group;
use Magento\Authorization\Model\Acl\Role\User;
use Magento\Authorization\Model\ResourceModel\Role as RoleResource;
use Magento\Authorization\Model\RoleFactory;
use Magento\Authorization\Model\RulesFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\Acl\Data\CacheInterface as AclDataCache;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Assert;

/**
 * Admin callers whose ACL role grants exactly the resources a test names
 *
 * The role is stored for a user id no admin account has: Magento's Web API ACL looks a caller's role up by user type
 * and id only, so no account is needed. Run it inside a DB-isolated test so the roles are rolled back.
 */
class AdminRoles
{
    /**
     * Store a role for the admin with this id and return that admin as a user context
     *
     * @param list<string> $resources
     * @param int $userId An id no admin user has, used only for this role
     * @return UserContextInterface
     */
    public static function grant(array $resources, int $userId): UserContextInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $roleFactory = $objectManager->get(RoleFactory::class);
        Assert::assertInstanceOf(RoleFactory::class, $roleFactory);
        $roleResource = $objectManager->get(RoleResource::class);
        Assert::assertInstanceOf(RoleResource::class, $roleResource);
        $rulesFactory = $objectManager->get(RulesFactory::class);
        Assert::assertInstanceOf(RulesFactory::class, $rulesFactory);

        $group = $roleFactory->create();
        $group->setRoleName('MCP test group ' . $userId)
            ->setRoleType(Group::ROLE_TYPE)
            ->setUserType((string) UserContextInterface::USER_TYPE_ADMIN)
            ->setParentId(0)
            ->setTreeLevel(1);
        $roleResource->save($group);
        $groupId = $group->getId();
        Assert::assertIsNumeric($groupId);
        $rulesFactory->create()->setData('role_id', (int) $groupId)->setData('resources', $resources)->saveRel();

        $user = $roleFactory->create();
        $user->setRoleName('mcp_test_' . $userId)
            ->setRoleType(User::ROLE_TYPE)
            ->setUserId($userId)
            ->setUserType((string) UserContextInterface::USER_TYPE_ADMIN)
            ->setParentId((int) $groupId)
            ->setTreeLevel(2);
        $roleResource->save($user);

        self::forgetAcl();

        return new FixedUserContext($userId);
    }

    /**
     * Make Magento build its ACL again, so the roles stored since are part of it
     *
     * @return void
     */
    public static function forgetAcl(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $aclCache = $objectManager->get(AclDataCache::class);
        Assert::assertInstanceOf(AclDataCache::class, $aclCache);
        $aclCache->clean();
        $aclBuilder = $objectManager->get(AclBuilder::class);
        Assert::assertInstanceOf(AclBuilder::class, $aclBuilder);
        $aclBuilder->resetRuntimeAcl();
    }
}
