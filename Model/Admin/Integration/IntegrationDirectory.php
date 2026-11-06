<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Integration\Model\Integration;
use Magento\Integration\Model\ResourceModel\Integration\CollectionFactory;

/**
 * Lists the store's integrations from Magento's integration table and judges each one's readiness for MCP
 *
 * Integrations are read through the integration collection, never through the integration service: the service's
 * get() makes Magento read the integration's ACL once more for every integration. No integration model leaves this
 * class, and the token of an integration is read only to know whether a usable one exists; it is never returned or
 * kept.
 *
 * Reading an integration's ACL replaces the ACL of the request. Magento builds the ACL for one role at a time: the
 * ACL retriever drops the runtime ACL of the shared ACL builder and builds it again with the integration's rules only,
 * and every later authorization check of the request is answered from that object, for the admin too — so the admin
 * would be denied everything else the page checks after the listing. That is why the runtime ACL is dropped again
 * once the readiness was read (also when reading failed): the next check builds it for the admin's own role.
 *
 * An integration whose ACL allows "Magento_Backend::all" has every resource, the MCP endpoint's included. One that
 * has no ACL role at all has no resources, so it cannot use the endpoint either; it does not fail the listing.
 *
 * @phpstan-import-type IntegrationRow from IntegrationDirectoryInterface
 */
class IntegrationDirectory implements IntegrationDirectoryInterface
{
    private const ENDPOINT_RESOURCE = 'Hryvinskyi_McpServer::access';
    private const ALL_RESOURCES = 'Magento_Backend::all';

    /**
     * @param CollectionFactory $integrations
     * @param AclRetriever $aclRetriever
     * @param AclBuilder $aclBuilder
     * @param AccessTokenReaderInterface $accessTokens
     */
    public function __construct(
        private readonly CollectionFactory $integrations,
        private readonly AclRetriever $aclRetriever,
        private readonly AclBuilder $aclBuilder,
        private readonly AccessTokenReaderInterface $accessTokens
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException When an integration's ACL cannot be read
     */
    public function all(): array
    {
        $rows = [];
        try {
            foreach ($this->integrations->create()->getItems() as $integration) {
                $rows[] = [
                    'id' => $this->idOf($integration),
                    'name' => $this->nameOf($integration),
                    'readiness' => $this->readinessOfIntegration($integration),
                ];
            }
        } finally {
            $this->aclBuilder->resetRuntimeAcl();
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
                ?: $left['id'] <=> $right['id']
        );

        return $rows;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException When the integration's ACL cannot be read
     */
    public function readinessOf(int $integrationId): ?IntegrationReadiness
    {
        $collection = $this->integrations->create()->addFieldToFilter(Integration::ID, ['eq' => $integrationId]);
        $integration = $collection->getFirstItem();
        if ($this->idOf($integration) !== $integrationId) {
            return null;
        }

        try {
            return $this->readinessOfIntegration($integration);
        } finally {
            $this->aclBuilder->resetRuntimeAcl();
        }
    }

    /**
     * The readiness of one loaded integration, reading its ACL and its token only as far as needed
     *
     * The checks run in the order of precedence and stop at the first that fails: the status first (to be
     * re-authorised, or not active), then the ACL, then the token. So the ACL of an integration that is not active is
     * never read, and neither is the token of one whose ACL keeps it from the endpoint.
     *
     * @param DataObject $integration
     * @return IntegrationReadiness
     * @throws LocalizedException When the integration's ACL cannot be read
     */
    private function readinessOfIntegration(DataObject $integration): IntegrationReadiness
    {
        $status = $this->intOf($integration, Integration::STATUS);
        if ($status === Integration::STATUS_RECREATED) {
            return IntegrationReadiness::NeedsReauthorisation;
        }
        if ($status !== Integration::STATUS_ACTIVE) {
            return IntegrationReadiness::Inactive;
        }
        if (!$this->hasEndpointAccess($this->idOf($integration))) {
            return IntegrationReadiness::NoEndpointAccess;
        }
        $token = $this->accessTokens->usableTokenOf($this->intOf($integration, Integration::CONSUMER_ID));

        return $token !== null ? IntegrationReadiness::Ready : IntegrationReadiness::NoToken;
    }

    /**
     * Whether the integration's ACL allows the MCP endpoint
     *
     * @param int $integrationId
     * @return bool
     * @throws LocalizedException When the ACL cannot be read for another reason than a missing role
     */
    private function hasEndpointAccess(int $integrationId): bool
    {
        try {
            $resources = $this->aclRetriever->getAllowedResourcesByUser(
                (string) UserContextInterface::USER_TYPE_INTEGRATION,
                $integrationId
            );
        } catch (AuthorizationException) {
            return false;
        }

        return in_array(self::ENDPOINT_RESOURCE, $resources, true)
            || in_array(self::ALL_RESOURCES, $resources, true);
    }

    /**
     * The integration's id; 0 for an empty item
     *
     * @param DataObject $integration
     * @return int
     */
    private function idOf(DataObject $integration): int
    {
        return $this->intOf($integration, Integration::ID);
    }

    /**
     * The integration's name
     *
     * @param DataObject $integration
     * @return string
     */
    private function nameOf(DataObject $integration): string
    {
        $name = $integration->getData(Integration::NAME);

        return is_scalar($name) ? (string) $name : '';
    }

    /**
     * A whole-number field of the integration; 0 when it is not set
     *
     * @param DataObject $integration
     * @param string $field
     * @return int
     */
    private function intOf(DataObject $integration, string $field): int
    {
        $value = $integration->getData($field);

        return is_numeric($value) ? (int) $value : 0;
    }
}
