<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A verdict carries a token exactly when the integration is ready, so no verdict can hand out a token for an
 * integration that is not ready, or claim readiness without one
 */
class IntegrationVerdictTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    public function testAReadyVerdictCarriesItsToken(): void
    {
        $verdict = new IntegrationVerdict(IntegrationReadiness::Ready, self::TOKEN);

        self::assertSame(IntegrationReadiness::Ready, $verdict->getReadiness());
        self::assertSame(self::TOKEN, $verdict->getUsableToken());
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
    public function testAVerdictThatIsNotReadyCarriesNoToken(IntegrationReadiness $readiness): void
    {
        $verdict = new IntegrationVerdict($readiness);

        self::assertSame($readiness, $verdict->getReadiness());
        self::assertNull($verdict->getUsableToken());
    }

    /**
     * @param IntegrationReadiness $readiness
     * @return void
     */
    #[DataProvider('notReady')]
    public function testAVerdictThatIsNotReadyRefusesAToken(IntegrationReadiness $readiness): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IntegrationVerdict($readiness, self::TOKEN);
    }

    public function testAReadyVerdictRefusesToGoWithoutAToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IntegrationVerdict(IntegrationReadiness::Ready);
    }

    public function testAReadyVerdictRefusesAnEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IntegrationVerdict(IntegrationReadiness::Ready, '');
    }

    public function testTheRefusalNeverShowsTheToken(): void
    {
        try {
            new IntegrationVerdict(IntegrationReadiness::NoToken, self::TOKEN);
            self::fail('A verdict that is not ready took a token');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
        }
    }
}
