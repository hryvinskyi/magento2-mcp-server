<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Framework\Exception\LocalizedException;

/**
 * Judges one integration now, for whatever hands out its token
 *
 * Kept apart from the listing on purpose: a verdict carries the token of a ready integration, and the page that
 * lists integrations depends only on the listing, so it is never handed a type that can hold a token.
 */
interface IntegrationVerdictsInterface
{
    /**
     * The verdict on one integration, or null when there is no integration with this id
     *
     * The integration is read once and its token at most once.
     *
     * @param int $integrationId
     * @return IntegrationVerdict|null
     * @throws LocalizedException When the integration's ACL cannot be read
     */
    public function verdictOf(int $integrationId): ?IntegrationVerdict;
}
