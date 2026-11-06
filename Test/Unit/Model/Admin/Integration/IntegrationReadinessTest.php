<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Only a ready integration is ready, the values are the ones the page and the browser use, and each state says why
 */
class IntegrationReadinessTest extends TestCase
{
    public function testOnlyReadyIsReady(): void
    {
        foreach (IntegrationReadiness::cases() as $readiness) {
            self::assertSame($readiness === IntegrationReadiness::Ready, $readiness->isReady(), $readiness->value);
        }
    }

    public function testTheValuesAreTheOnesThePageAndTheBrowserUse(): void
    {
        self::assertSame(
            ['ready', 'inactive', 'needs-reauthorisation', 'no-endpoint-access', 'no-token'],
            array_map(
                static fn (IntegrationReadiness $readiness): string => $readiness->value,
                IntegrationReadiness::cases()
            )
        );
    }

    /**
     * @return array<string, array{IntegrationReadiness, string}>
     */
    public static function reasons(): array
    {
        return [
            'ready' => [IntegrationReadiness::Ready, 'Ready'],
            'inactive' => [
                IntegrationReadiness::Inactive,
                'Not activated yet. Activate it in System › Extensions › Integrations.',
            ],
            'to be re-authorised' => [
                IntegrationReadiness::NeedsReauthorisation,
                'Its settings changed. Reauthorize it in System › Extensions › Integrations.',
            ],
            'no endpoint access' => [
                IntegrationReadiness::NoEndpointAccess,
                'Its API resources leave out "Use the MCP endpoint". Allow that resource, or all resources.',
            ],
            'no token' => [
                IntegrationReadiness::NoToken,
                'It has no usable access token. Reauthorize it in System › Extensions › Integrations.',
            ],
        ];
    }

    /**
     * @param IntegrationReadiness $readiness
     * @param string $reason
     * @return void
     */
    #[DataProvider('reasons')]
    public function testEachStateSaysWhy(IntegrationReadiness $readiness, string $reason): void
    {
        self::assertSame($reason, $readiness->reason()->render());
    }
}
