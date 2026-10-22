<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\FieldCaller;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\HttpGraphQlClient;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlAbilitySource;
use Hryvinskyi\McpServer\Model\Source\GraphQl\RootFieldLister;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\CachedSchemaProvider;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectionSchemaProvider;
use Hryvinskyi\McpServer\Test\Integration\Fake\RefusingGraphQlClient;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Keeps a test class off the network: the container never hands out the HTTP GraphQL client, and a test fails when
 * anything asked the client in its place to send a request
 *
 * Before each test the shared GraphQL client, which every service asking for GraphQlClientInterface receives, is a
 * RefusingGraphQlClient, and the shared services that hold a client are dropped so they are built again over it.
 * After the test, any request that client recorded fails the test, and the refusing client is put back in place of
 * whatever client the test used. A test that needs answers hands its own client to useGraphQlClient().
 *
 * An HTTP client a test builds itself through create(), outside the shared instances, is not covered; such a test
 * must never let it send.
 */
trait BlocksRealGraphQlClient
{
    /**
     * Requests the refusing client had recorded when the test started
     *
     * @var int
     */
    private int $refusedRequestsBefore = 0;

    /**
     * Put the refusing client in place of the HTTP one
     *
     * @return void
     */
    #[Before]
    protected function blockRealGraphQlClient(): void
    {
        $this->refusedRequestsBefore = count(RefusingGraphQlClient::instance()->requests);
        $this->installGraphQlClient(RefusingGraphQlClient::instance());
    }

    /**
     * Fail the test when anything asked for a GraphQL request over HTTP, then put the refusing client back
     *
     * @return void
     */
    #[After]
    protected function assertNoGraphQlRequestWasSent(): void
    {
        $refused = array_slice(RefusingGraphQlClient::instance()->requests, $this->refusedRequestsBefore);
        $this->installGraphQlClient(RefusingGraphQlClient::instance());

        self::assertSame(
            [],
            array_column($refused, 'document'),
            'The test asked the GraphQL client to send requests over HTTP; switch the GraphQL source off or hand '
            . 'the test a fake client.'
        );
    }

    /**
     * Make this client the one the catalog's GraphQL source sends through, for the rest of the test
     *
     * @param GraphQlClientInterface $client
     * @return void
     */
    protected function useGraphQlClient(GraphQlClientInterface $client): void
    {
        $this->installGraphQlClient($client);
    }

    /**
     * Share this client as the GraphQL client, and drop the shared services built over another one
     *
     * @param GraphQlClientInterface $client
     * @return void
     */
    private function installGraphQlClient(GraphQlClientInterface $client): void
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        foreach ([
            HttpGraphQlClient::class,
            IntrospectionSchemaProvider::class,
            CachedSchemaProvider::class,
            RootFieldLister::class,
            FieldCaller::class,
            GraphQlAbilitySource::class,
            AbilityCatalog::class,
            AbilityInvoker::class,
        ] as $holder) {
            $objectManager->removeSharedInstance($holder);
        }
        $objectManager->addSharedInstance($client, HttpGraphQlClient::class);
    }
}
