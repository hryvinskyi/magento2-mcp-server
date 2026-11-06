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
use Magento\Integration\Model\Integration;
use Magento\Integration\Model\ResourceModel\Integration\CollectionFactory;

/**
 * Hands out an integration's access token after judging the integration's readiness again, at the moment it is asked
 *
 * The readiness the page showed when it was rendered is only a display: the integration may have been deactivated,
 * lost its endpoint resource or had its token revoked since. The directory decides here, for this one integration,
 * and the token is read only when it says ready. The token never appears in an exception's message.
 */
class IntegrationTokens implements IntegrationTokensInterface
{
    /**
     * @param IntegrationDirectoryInterface $directory
     * @param CollectionFactory $integrations
     * @param AccessTokenReaderInterface $accessTokens
     */
    public function __construct(
        private readonly IntegrationDirectoryInterface $directory,
        private readonly CollectionFactory $integrations,
        private readonly AccessTokenReaderInterface $accessTokens
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException When the integration's ACL cannot be read
     */
    public function tokenOf(int $integrationId): string
    {
        $readiness = $this->directory->readinessOf($integrationId);
        if ($readiness === null) {
            throw new NoSuchEntityException(__('The integration with ID "%1" does not exist.', $integrationId));
        }
        if (!$readiness->isReady()) {
            throw new IntegrationNotReadyException($readiness->reason());
        }

        $token = $this->accessTokens->usableTokenOf($this->consumerIdOf($integrationId));
        if ($token === null) {
            throw new IntegrationNotReadyException(IntegrationReadiness::NoToken->reason());
        }

        return $token;
    }

    /**
     * The id of the integration's OAuth consumer; 0 when the integration is gone
     *
     * @param int $integrationId
     * @return int
     */
    private function consumerIdOf(int $integrationId): int
    {
        $consumerId = $this->integrations->create()
            ->addFieldToFilter(Integration::ID, ['eq' => $integrationId])
            ->getFirstItem()
            ->getData(Integration::CONSUMER_ID);

        return is_numeric($consumerId) ? (int) $consumerId : 0;
    }
}
