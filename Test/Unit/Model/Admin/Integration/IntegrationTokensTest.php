<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\AccessTokenReaderInterface;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationDirectory;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationNotReadyException;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationTokens;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationVerdict;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationVerdictFactory;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationVerdictsInterface;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Integration\Model\Integration;
use Magento\Integration\Model\ResourceModel\Integration\Collection;
use Magento\Integration\Model\ResourceModel\Integration\CollectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The token handed out is the one the verdict taken at that moment found, and only for a ready integration
 */
class IntegrationTokensTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    public function testOneTokenRequestLoadsTheIntegrationOnceAndReadsItsTokenOnce(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(new DataObject([
            Integration::ID => '5',
            Integration::STATUS => (string) Integration::STATUS_ACTIVE,
            Integration::CONSUMER_ID => '55',
        ]));
        /** @var CollectionFactory&MockObject $integrations */
        $integrations = $this->createMock(CollectionFactory::class);
        $integrations->expects(self::once())->method('create')->willReturn($collection);
        /** @var AclRetriever&MockObject $acl */
        $acl = $this->createMock(AclRetriever::class);
        $acl->method('getAllowedResourcesByUser')->willReturn(['Hryvinskyi_McpServer::access']);
        /** @var AccessTokenReaderInterface&MockObject $reader */
        $reader = $this->createMock(AccessTokenReaderInterface::class);
        $reader->expects(self::once())->method('usableTokenOf')->with(55)->willReturn(self::TOKEN);

        $directory = new IntegrationDirectory(
            $integrations,
            $acl,
            $this->createMock(AclBuilder::class),
            $reader,
            new IntegrationVerdictFactory(
                new ConstructingObjectManager([IntegrationVerdict::class => IntegrationVerdict::class])
            )
        );
        $token = (new IntegrationTokens($directory))->tokenOf(5);

        self::assertTrue(hash_equals(self::TOKEN, $token), 'The token the verdict found is handed out');
    }

    public function testAReadyIntegrationsTokenIsTheOneItsVerdictCarries(): void
    {
        $token = $this->tokens(new IntegrationVerdict(IntegrationReadiness::Ready, self::TOKEN))->tokenOf(5);

        self::assertTrue(hash_equals(self::TOKEN, $token));
    }

    public function testAnUnknownIntegrationHasNoToken(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->tokens(null)->tokenOf(5);
    }

    /**
     * @return array<string, array{IntegrationReadiness}>
     */
    public static function notReady(): array
    {
        return [
            'inactive' => [IntegrationReadiness::Inactive],
            'to be re-authorised' => [IntegrationReadiness::NeedsReauthorisation],
            'no endpoint access' => [IntegrationReadiness::NoEndpointAccess],
            'no token' => [IntegrationReadiness::NoToken],
        ];
    }

    /**
     * @param IntegrationReadiness $readiness
     * @return void
     */
    #[DataProvider('notReady')]
    public function testAnIntegrationThatIsNotReadyIsRefusedWithItsReason(IntegrationReadiness $readiness): void
    {
        try {
            $this->tokens(new IntegrationVerdict($readiness))->tokenOf(5);
            self::fail('A token was handed out for an integration that is not ready');
        } catch (IntegrationNotReadyException $exception) {
            self::assertInstanceOf(LocalizedException::class, $exception);
            self::assertSame($readiness->reason()->render(), $exception->getMessage());
        }
    }

    /**
     * The tokens of integration 5, whose verdict is the one given, asked for exactly once
     *
     * @param IntegrationVerdict|null $verdict
     * @return IntegrationTokens
     */
    private function tokens(?IntegrationVerdict $verdict): IntegrationTokens
    {
        /** @var IntegrationVerdictsInterface&MockObject $verdicts */
        $verdicts = $this->createMock(IntegrationVerdictsInterface::class);
        $verdicts->expects(self::once())->method('verdictOf')->with(5)->willReturn($verdict);

        return new IntegrationTokens($verdicts);
    }
}
