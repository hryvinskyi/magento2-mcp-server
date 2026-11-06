<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The access token of one integration an MCP client can use
 *
 * The token is handed out only for an integration that is ready at the moment it is asked for; the readiness a page
 * showed earlier decides nothing.
 */
interface IntegrationTokensInterface
{
    /**
     * The access token of the integration with this id
     *
     * @param int $integrationId
     * @return string
     * @throws NoSuchEntityException When there is no integration with this id
     * @throws IntegrationNotReadyException When the integration is not ready; its message is the readiness reason
     */
    public function tokenOf(int $integrationId): string;
}
