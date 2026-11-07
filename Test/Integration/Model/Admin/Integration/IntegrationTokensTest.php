<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationNotReadyException;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationTokensInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\CreatesIntegrations;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Handing out a real integration's token through the real container reads the integration's row once and its token
 * once, and hands out the token Magento stored for the integration's consumer
 */
#[DbIsolation(true)]
class IntegrationTokensTest extends TestCase
{
    use CreatesIntegrations;

    private const ACCESS = 'Hryvinskyi_McpServer::access';

    public function testAReadyIntegrationsTokenIsHandedOutReadingItsRowAndItsTokenOnce(): void
    {
        $integration = $this->createIntegration('mcp-test token once', [self::ACCESS]);

        $token = '';
        $selects = $this->selectsDuring(function () use ($integration, &$token): void {
            $token = $this->tokens()->tokenOf($integration['id']);
        });

        self::assertTrue(hash_equals($integration['token'], $token), 'The consumer\'s stored token is handed out');
        self::assertSame(
            ['integration' => 1, 'oauth_token' => 1],
            [
                'integration' => $this->countFrom($selects, 'integration'),
                'oauth_token' => $this->countFrom($selects, 'oauth_token'),
            ]
        );
    }

    public function testAnIntegrationWhoseTokenWasRevokedIsRefusedAsHavingNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test token revoked', [self::ACCESS]);
        $this->revokeTokenOf($integration['consumerId']);

        $this->expectException(IntegrationNotReadyException::class);
        $this->expectExceptionMessage(IntegrationReadiness::NoToken->reason()->render());

        $this->tokens()->tokenOf($integration['id']);
    }

    /**
     * The SELECT statements the default connection ran while the call ran
     *
     * @param callable(): void $call
     * @return list<string>
     */
    private function selectsDuring(callable $call): array
    {
        $connection = $this->resource()->getConnection();
        self::assertInstanceOf(\Zend_Db_Adapter_Abstract::class, $connection);
        $profiler = $connection->getProfiler();
        $wasEnabled = $profiler->getEnabled();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $call();
            $profiles = $profiler->getQueryProfiles(\Zend_Db_Profiler::SELECT);
        } finally {
            $profiler->setEnabled($wasEnabled);
        }

        $selects = [];
        foreach (is_array($profiles) ? $profiles : [] as $profile) {
            self::assertInstanceOf(\Zend_Db_Profiler_Query::class, $profile);
            $selects[] = $profile->getQuery();
        }

        return $selects;
    }

    /**
     * How many of these statements select from the table
     *
     * @param list<string> $selects
     * @param string $table
     * @return int
     */
    private function countFrom(array $selects, string $table): int
    {
        $from = 'FROM `' . $this->resource()->getTableName($table) . '`';

        return count(array_filter($selects, static fn (string $sql): bool => str_contains($sql, $from)));
    }

    /**
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);

        return $resource;
    }

    /**
     * @return IntegrationTokensInterface
     */
    private function tokens(): IntegrationTokensInterface
    {
        $tokens = Bootstrap::getObjectManager()->get(IntegrationTokensInterface::class);
        self::assertInstanceOf(IntegrationTokensInterface::class, $tokens);

        return $tokens;
    }
}
