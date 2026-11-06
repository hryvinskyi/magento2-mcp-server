<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\AccessTokenReader;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationDirectory;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Test\Unit\Fake\BuildsTokenModels;
use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\ResourceModel\Integration\Collection;
use Magento\Integration\Model\ResourceModel\Integration\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How the directory maps what Magento stores about an integration to its readiness, and that it gives the request
 * its ACL back after reading an integration's
 *
 * Magento's collaborators are doubles here, so these tests prove the mapping only; the integration test proves the
 * rule against Magento's real ACL.
 */
class IntegrationDirectoryTest extends TestCase
{
    use BuildsTokenModels;

    private const ACCESS = 'Hryvinskyi_McpServer::access';
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    /**
     * Resources by integration id; an id without an entry has no ACL role
     *
     * @var array<int, list<string>>
     */
    private array $resources = [];

    /**
     * Access tokens by consumer id; a consumer without an entry has none
     *
     * @var array<int, Token>
     */
    private array $tokens = [];

    /**
     * @var list<int>
     */
    private array $aclReadFor = [];

    /**
     * @var list<int>
     */
    private array $tokenReadFor = [];

    public function testAnActiveIntegrationThatMayUseTheEndpointAndHasATokenIsReady(): void
    {
        $this->resources[1] = ['Magento_Catalog::products', self::ACCESS];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(IntegrationReadiness::Ready, $this->readinessOf($this->integration(1, 'A', 1, 11)));
    }

    public function testAllResourcesCountAsAccessToTheEndpoint(): void
    {
        $this->resources[1] = ['Magento_Backend::all'];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(IntegrationReadiness::Ready, $this->readinessOf($this->integration(1, 'A', 1, 11)));
    }

    public function testAnIntegrationToBeReauthorisedIsNotReadyAndItsAclIsNotRead(): void
    {
        $this->resources[1] = [self::ACCESS];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(
            IntegrationReadiness::NeedsReauthorisation,
            $this->readinessOf($this->integration(1, 'A', Integration::STATUS_RECREATED, 11))
        );
        self::assertSame([], $this->aclReadFor);
    }

    public function testAnInactiveIntegrationIsNotReady(): void
    {
        $this->resources[1] = [self::ACCESS];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(
            IntegrationReadiness::Inactive,
            $this->readinessOf($this->integration(1, 'A', Integration::STATUS_INACTIVE, 11))
        );
    }

    public function testResourcesWithoutTheEndpointsMeanNoEndpointAccess(): void
    {
        $this->resources[1] = ['Magento_Catalog::products'];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(IntegrationReadiness::NoEndpointAccess, $this->readinessOf($this->integration(1, 'A', 1, 11)));
    }

    public function testAnIntegrationWithoutAnAclRoleHasNoEndpointAccess(): void
    {
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(IntegrationReadiness::NoEndpointAccess, $this->readinessOf($this->integration(1, 'A', 1, 11)));
    }

    public function testAnIntegrationWithoutATokenHasNoToken(): void
    {
        $this->resources[1] = [self::ACCESS];

        self::assertSame(IntegrationReadiness::NoToken, $this->readinessOf($this->integration(1, 'A', 1, 11)));
    }

    public function testAnIntegrationWhoseTokenWasRevokedHasNoToken(): void
    {
        $this->resources[1] = [self::ACCESS];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 1);

        self::assertSame(IntegrationReadiness::NoToken, $this->readinessOf($this->integration(1, 'A', 1, 11)));
    }

    public function testAnInactiveIntegrationIsInactiveWhateverItsAclAndTokenAndNeitherIsRead(): void
    {
        self::assertSame(
            IntegrationReadiness::Inactive,
            $this->readinessOf($this->integration(1, 'A', Integration::STATUS_INACTIVE, 11))
        );
        self::assertSame([[], []], [$this->aclReadFor, $this->tokenReadFor]);
    }

    public function testAnIntegrationToBeReauthorisedIsSoWhateverItsAclAndTokenAndNeitherIsRead(): void
    {
        self::assertSame(
            IntegrationReadiness::NeedsReauthorisation,
            $this->readinessOf($this->integration(1, 'A', Integration::STATUS_RECREATED, 11))
        );
        self::assertSame([[], []], [$this->aclReadFor, $this->tokenReadFor]);
    }

    public function testAnUnknownStatusCountsAsNotActivated(): void
    {
        $this->resources[1] = [self::ACCESS];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        self::assertSame(IntegrationReadiness::Inactive, $this->readinessOf($this->integration(1, 'A', 7, 11)));
    }

    public function testNoEndpointAccessWinsOverNoTokenAndTheTokenIsNotRead(): void
    {
        $this->resources[1] = ['Magento_Catalog::products'];

        self::assertSame(IntegrationReadiness::NoEndpointAccess, $this->readinessOf($this->integration(1, 'A', 1, 11)));
        self::assertSame([[1], []], [$this->aclReadFor, $this->tokenReadFor]);
    }

    public function testTheTokenIsReadForTheIntegrationsConsumer(): void
    {
        $this->resources[1] = [self::ACCESS];
        $this->tokens[11] = $this->tokenModel(self::TOKEN, 0);

        $this->readinessOf($this->integration(1, 'A', 1, 11));

        self::assertSame([[1], [11]], [$this->aclReadFor, $this->tokenReadFor]);
    }

    public function testTheListingHoldsIdNameAndReadinessSortedByNameWhateverTheCase(): void
    {
        $this->resources = [1 => [self::ACCESS], 2 => [self::ACCESS], 3 => ['Magento_Catalog::products']];
        $this->tokens = [11 => $this->tokenModel(self::TOKEN, 0), 33 => $this->tokenModel(self::TOKEN, 0)];

        $rows = $this->directory(
            [
                $this->integration(1, 'zeta', 1, 11),
                $this->integration(2, 'Beta', 1, 22),
                $this->integration(3, 'alpha', 1, 33),
                $this->integration(4, 'Gamma', Integration::STATUS_INACTIVE, 44),
            ],
            1
        )->all();

        self::assertSame(
            [
                ['id' => 3, 'name' => 'alpha', 'readiness' => IntegrationReadiness::NoEndpointAccess],
                ['id' => 2, 'name' => 'Beta', 'readiness' => IntegrationReadiness::NoToken],
                ['id' => 4, 'name' => 'Gamma', 'readiness' => IntegrationReadiness::Inactive],
                ['id' => 1, 'name' => 'zeta', 'readiness' => IntegrationReadiness::Ready],
            ],
            $rows
        );
        self::assertSame([1, 2, 3], $this->aclReadFor, 'The ACL of the inactive integration is not read');
    }

    public function testNoIntegrationsListNothing(): void
    {
        self::assertSame([], $this->directory([], 1)->all());
    }

    public function testTheRequestsAclIsResetOnceAfterTheListing(): void
    {
        $this->resources = [1 => [self::ACCESS], 2 => [self::ACCESS]];

        $this->directory([$this->integration(1, 'A', 1, 11), $this->integration(2, 'B', 1, 22)], 1)->all();
    }

    public function testTheRequestsAclIsResetAfterTheListingAlsoWhenReadingAnAclFails(): void
    {
        $this->resources[1] = [self::ACCESS];
        $directory = $this->directory([$this->integration(1, 'A', 1, 11), $this->integration(2, 'B', 1, 22)], 1, 2);

        $this->expectException(LocalizedException::class);
        $directory->all();
    }

    public function testTheRequestsAclIsResetAfterReadingOneIntegrationsReadiness(): void
    {
        $this->resources[1] = [self::ACCESS];

        $this->directory([$this->integration(1, 'A', 1, 11)], 1)->readinessOf(1);
    }

    public function testTheRequestsAclIsResetAfterOneIntegrationsReadinessAlsoWhenReadingItsAclFails(): void
    {
        $directory = $this->directory([$this->integration(1, 'A', 1, 11)], 1, 1);

        $this->expectException(LocalizedException::class);
        $directory->readinessOf(1);
    }

    public function testAnUnknownIntegrationHasNoReadinessAndNothingIsRead(): void
    {
        self::assertNull($this->directory([$this->integration(1, 'A', 1, 11)], 0)->readinessOf(9));
        self::assertSame([], $this->aclReadFor);
    }

    /**
     * The readiness of the one integration, read through readinessOf()
     *
     * @param DataObject $integration
     * @return IntegrationReadiness|null
     */
    private function readinessOf(DataObject $integration): ?IntegrationReadiness
    {
        $id = $integration->getData(Integration::ID);
        self::assertIsInt($id);

        return $this->directory([$integration], 1)->readinessOf($id);
    }

    /**
     * An integration as the collection loads it
     *
     * @param int $id
     * @param string $name
     * @param int $status
     * @param int $consumerId
     * @return DataObject
     */
    private function integration(int $id, string $name, int $status, int $consumerId): DataObject
    {
        return new DataObject([
            Integration::ID => $id,
            Integration::NAME => $name,
            Integration::STATUS => (string) $status,
            Integration::CONSUMER_ID => (string) $consumerId,
        ]);
    }

    /**
     * A directory over these integrations, whose ACL builder must be reset exactly this many times
     *
     * @param list<DataObject> $integrations
     * @param int $resets
     * @param int|null $failingAclOf The integration whose ACL cannot be read
     * @return IntegrationDirectory
     */
    private function directory(array $integrations, int $resets, ?int $failingAclOf = null): IntegrationDirectory
    {
        /** @var CollectionFactory&MockObject $factory */
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Collection => $this->collection($integrations));

        /** @var AclRetriever&MockObject $aclRetriever */
        $aclRetriever = $this->createMock(AclRetriever::class);
        $aclRetriever->method('getAllowedResourcesByUser')->willReturnCallback(
            function (mixed $userType, mixed $userId) use ($failingAclOf): array {
                self::assertEquals(UserContextInterface::USER_TYPE_INTEGRATION, $userType);
                self::assertIsInt($userId);
                $this->aclReadFor[] = $userId;
                if ($userId === $failingAclOf) {
                    throw new LocalizedException(__('The ACL cannot be read.'));
                }
                if (!isset($this->resources[$userId])) {
                    throw new AuthorizationException(__('No role.'));
                }

                return $this->resources[$userId];
            }
        );

        /** @var AclBuilder&MockObject $aclBuilder */
        $aclBuilder = $this->createMock(AclBuilder::class);
        $aclBuilder->expects(self::exactly($resets))->method('resetRuntimeAcl')->willReturnSelf();

        /** @var OauthServiceInterface&MockObject $oauth */
        $oauth = $this->createMock(OauthServiceInterface::class);
        $oauth->method('getAccessToken')->willReturnCallback(
            function (mixed $consumerId): Token|false {
                self::assertIsInt($consumerId);
                $this->tokenReadFor[] = $consumerId;

                return $this->tokens[$consumerId] ?? false;
            }
        );

        return new IntegrationDirectory($factory, $aclRetriever, $aclBuilder, new AccessTokenReader($oauth));
    }

    /**
     * A collection of these integrations that also answers a filter by integration id
     *
     * @param list<DataObject> $integrations
     * @return Collection
     */
    private function collection(array $integrations): Collection
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('getItems')->willReturn($integrations);
        $selected = $integrations;
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (mixed $field, mixed $condition) use ($collection, $integrations, &$selected): Collection {
                self::assertSame(Integration::ID, $field);
                self::assertIsArray($condition);
                $selected = array_values(array_filter(
                    $integrations,
                    static fn (DataObject $integration): bool => $integration->getData(Integration::ID)
                        === ($condition['eq'] ?? null)
                ));

                return $collection;
            }
        );
        $collection->method('getFirstItem')->willReturnCallback(
            static function () use (&$selected): DataObject {
                return $selected[0] ?? new DataObject();
            }
        );

        return $collection;
    }
}
