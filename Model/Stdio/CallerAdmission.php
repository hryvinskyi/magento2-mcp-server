<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\AuthorizationInterface;

/**
 * Decides whether a stdio process may run as the admin with this login, and makes it that admin
 *
 * The order is fixed, because each step relies on the one before:
 * 1. the login must name an active admin account that is neither locked nor past its expiry date;
 * 2. the account is set on the CLI admin user context;
 * 3. Magento's user context must now answer that admin. It does not when it was built before the Web API area was
 *    loaded, or chose its answer before step 2: asked earlier, it falls through to the admin session context, which
 *    starts a PHP session on the command line, then to the guest, and keeps that choice for the process;
 * 4. only then the admin's role must allow using the MCP endpoint: asked before step 2, the ACL would judge a guest;
 * 5. the server must be enabled.
 */
class CallerAdmission
{
    public const ACCESS_RESOURCE = 'Hryvinskyi_McpServer::access';

    /**
     * @param AdminAccountsInterface $adminAccounts
     * @param CliAdminUserContext $cliAdminUserContext
     * @param UserContextInterface $userContext
     * @param AuthorizationInterface $authorization
     * @param ConfigInterface $config
     * @param CallerInterfaceFactory $callerFactory
     */
    public function __construct(
        private readonly AdminAccountsInterface $adminAccounts,
        private readonly CliAdminUserContext $cliAdminUserContext,
        private readonly UserContextInterface $userContext,
        private readonly AuthorizationInterface $authorization,
        private readonly ConfigInterface $config,
        private readonly CallerInterfaceFactory $callerFactory
    ) {
    }

    /**
     * Make this process the admin with this login, and return that admin as the caller
     *
     * @param string $login
     * @return CallerInterface
     * @throws CallerRefusedException When the admin or the server refuses
     */
    public function admit(string $login): CallerInterface
    {
        $account = $this->adminAccounts->byLogin($login);
        if ($account === null) {
            throw new CallerRefusedException(__('There is no admin user with the login "%1".', $login));
        }
        if (!$account->isActive()) {
            throw new CallerRefusedException(__('The admin user "%1" is inactive.', $login));
        }
        if ($account->isLocked()) {
            throw new CallerRefusedException(__('The admin user "%1" is locked.', $login));
        }
        if ($account->isExpired()) {
            throw new CallerRefusedException(__('The admin user "%1" has expired.', $login));
        }

        $this->cliAdminUserContext->actAs($account->getId());

        if ((int) $this->userContext->getUserType() !== UserContextInterface::USER_TYPE_ADMIN
            || (int) $this->userContext->getUserId() !== $account->getId()
        ) {
            throw new CallerRefusedException(
                __('The webapi_rest user context could not be loaded in this process.')
            );
        }

        if (!$this->authorization->isAllowed(self::ACCESS_RESOURCE)) {
            throw new CallerRefusedException(__(
                'The role of the admin user "%1" does not allow "Use the MCP endpoint" (%2).',
                $login,
                self::ACCESS_RESOURCE
            ));
        }

        if (!$this->config->isEnabled()) {
            throw new CallerRefusedException(__('The MCP server is disabled.'));
        }

        return $this->callerFactory->create([
            'userType' => UserContextInterface::USER_TYPE_ADMIN,
            'userId' => $account->getId(),
        ]);
    }
}
