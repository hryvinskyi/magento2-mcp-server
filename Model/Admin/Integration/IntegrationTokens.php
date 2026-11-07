<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Hands out an integration's access token after judging the integration's readiness again, at the moment it is asked
 *
 * The readiness the page showed when it was rendered is only a display: the integration may have been deactivated,
 * lost its endpoint resource or had its token revoked since. The verdict is taken here, for this one integration,
 * and the token handed out is the one that verdict found, so neither the integration nor its token is read twice.
 * The token never appears in an exception's message.
 */
class IntegrationTokens implements IntegrationTokensInterface
{
    /**
     * @param IntegrationVerdictsInterface $verdicts
     */
    public function __construct(
        private readonly IntegrationVerdictsInterface $verdicts
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException When the integration's ACL cannot be read
     */
    public function tokenOf(int $integrationId): string
    {
        $verdict = $this->verdicts->verdictOf($integrationId);
        if ($verdict === null) {
            throw new NoSuchEntityException(__('The integration with ID "%1" does not exist.', $integrationId));
        }

        $token = $verdict->getUsableToken();
        if ($token === null) {
            throw new IntegrationNotReadyException($verdict->getReadiness()->reason());
        }

        return $token;
    }
}
