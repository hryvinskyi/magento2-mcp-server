<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\AccessTokenReaderInterface;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationDirectoryInterface;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationNotReadyException;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationTokens;
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
 * The token is handed out only when the directory, asked at that moment, says the integration is ready; otherwise the
 * token is never read
 */
class IntegrationTokensTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    public function testAReadyIntegrationsTokenIsReadForItsConsumer(): void
    {
        /** @var AccessTokenReaderInterface&MockObject $reader */
        $reader = $this->createMock(AccessTokenReaderInterface::class);
        $reader->expects(self::once())->method('usableTokenOf')->with(55)->willReturn(self::TOKEN);

        $token = $this->tokens(IntegrationReadiness::Ready, $reader)->tokenOf(5);

        self::assertTrue(hash_equals(self::TOKEN, $token), 'The token of the integration\'s consumer is handed out');
    }

    public function testAnUnknownIntegrationHasNoTokenAndNothingIsRead(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->tokens(null, $this->untouchedReader())->tokenOf(5);
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
    public function testAnIntegrationThatIsNotReadyIsRefusedWithItsReasonAndItsTokenIsNeverRead(
        IntegrationReadiness $readiness
    ): void {
        try {
            $this->tokens($readiness, $this->untouchedReader())->tokenOf(5);
            self::fail('A token was handed out for an integration that is not ready');
        } catch (IntegrationNotReadyException $exception) {
            self::assertInstanceOf(LocalizedException::class, $exception);
            self::assertSame($readiness->reason()->render(), $exception->getMessage());
        }
    }

    public function testATokenRevokedSinceTheReadinessWasReadIsRefusedAsNoToken(): void
    {
        /** @var AccessTokenReaderInterface&MockObject $reader */
        $reader = $this->createMock(AccessTokenReaderInterface::class);
        $reader->method('usableTokenOf')->willReturn(null);

        $this->expectException(IntegrationNotReadyException::class);
        $this->expectExceptionMessage(IntegrationReadiness::NoToken->reason()->render());

        $this->tokens(IntegrationReadiness::Ready, $reader)->tokenOf(5);
    }

    /**
     * A token reader that must not be asked
     *
     * @return AccessTokenReaderInterface
     */
    private function untouchedReader(): AccessTokenReaderInterface
    {
        /** @var AccessTokenReaderInterface&MockObject $reader */
        $reader = $this->createMock(AccessTokenReaderInterface::class);
        $reader->expects(self::never())->method('usableTokenOf');

        return $reader;
    }

    /**
     * The tokens of integration 5, whose consumer is 55 and whose readiness the directory states as given
     *
     * @param IntegrationReadiness|null $readiness
     * @param AccessTokenReaderInterface $reader
     * @return IntegrationTokens
     */
    private function tokens(?IntegrationReadiness $readiness, AccessTokenReaderInterface $reader): IntegrationTokens
    {
        /** @var IntegrationDirectoryInterface&MockObject $directory */
        $directory = $this->createMock(IntegrationDirectoryInterface::class);
        $directory->expects(self::once())->method('readinessOf')->with(5)->willReturn($readiness);

        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->with(Integration::ID, ['eq' => 5])->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            new DataObject([Integration::ID => '5', Integration::CONSUMER_ID => '55'])
        );
        /** @var CollectionFactory&MockObject $factory */
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new IntegrationTokens($directory, $factory, $reader);
    }
}
