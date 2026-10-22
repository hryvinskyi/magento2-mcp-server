<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\CachedSchemaProvider;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectionSchemaProvider;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\SchemaProviderInterface;
use Hryvinskyi\McpServer\Test\Integration\Fake\FixtureGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\NamesGraphQlEndpoint;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Cache\CacheConstants;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The schema provider the container builds from di.xml, over a client that answers from a stored introspection: the
 * schema it reads, kept in the module's cache type and for the request, and a failed read remembered
 */
class CachedSchemaProviderTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use NamesGraphQlEndpoint;

    /**
     * @var bool
     */
    private bool $cacheWasEnabled = false;

    protected function setUp(): void
    {
        $this->cacheWasEnabled = $this->cacheState()->isEnabled(McpCache::TYPE_IDENTIFIER);
        $this->cacheState()->setEnabled(McpCache::TYPE_IDENTIFIER, true);
        $this->cleanSchemas();
    }

    protected function tearDown(): void
    {
        $this->cacheState()->setEnabled(McpCache::TYPE_IDENTIFIER, true);
        $this->cleanSchemas();
        $this->cacheState()->setEnabled(McpCache::TYPE_IDENTIFIER, $this->cacheWasEnabled);
    }

    public function testTheContainerPutsTheCacheInFrontOfIntrospection(): void
    {
        self::assertInstanceOf(
            CachedSchemaProvider::class,
            self::objectManager()->create(SchemaProviderInterface::class)
        );
    }

    public function testTheSchemaIsReadOnceAndTheNextRequestTakesItFromTheCache(): void
    {
        $client = new FixtureGraphQlClient();

        $schema = $this->provider($client)->schema();

        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertContains('products', $this->fieldNames($schema, $schema->getQueryTypeName()));
        self::assertContains('createEmptyCart', $this->fieldNames($schema, $schema->getMutationTypeName()));
        self::assertCount(1, $client->requests);
        self::assertStringStartsWith('query IntrospectionQuery', $client->requests[0]['document']);

        $nextRequest = new FixtureGraphQlClient();
        $cached = $this->provider($nextRequest)->schema();

        self::assertSame([], $nextRequest->requests);
        self::assertInstanceOf(IntrospectedSchema::class, $cached);
        self::assertSame($schema->getTypes(), $cached->getTypes());
        self::assertSame($schema->getQueryTypeName(), $cached->getQueryTypeName());
        self::assertSame($schema->getMutationTypeName(), $cached->getMutationTypeName());
    }

    public function testAFailedReadIsRememberedForTheNextRequests(): void
    {
        $failing = new FixtureGraphQlClient(true);

        self::assertNull($this->provider($failing)->schema());

        $nextRequest = new FixtureGraphQlClient();
        self::assertNull($this->provider($nextRequest)->schema());
        self::assertCount(1, $failing->requests);
        self::assertSame([], $nextRequest->requests);
    }

    public function testCleaningTheCacheTypeLetsTheSchemaBeReadAgain(): void
    {
        $this->provider(new FixtureGraphQlClient(true))->schema();
        $this->cleanSchemas();

        $client = new FixtureGraphQlClient();

        self::assertInstanceOf(IntrospectedSchema::class, $this->provider($client)->schema());
        self::assertCount(1, $client->requests);
    }

    public function testWithTheCacheTypeDisabledTwoReadsInOneRequestMakeOneCall(): void
    {
        $this->cacheState()->setEnabled(McpCache::TYPE_IDENTIFIER, false);
        $client = new FixtureGraphQlClient();
        $provider = $this->provider($client);

        $first = $provider->schema();
        $second = $provider->schema();

        self::assertInstanceOf(IntrospectedSchema::class, $first);
        self::assertSame($first, $second);
        self::assertCount(1, $client->requests);
    }

    public function testWithTheCacheTypeDisabledAFailureIsRememberedByTheProcess(): void
    {
        $this->cacheState()->setEnabled(McpCache::TYPE_IDENTIFIER, false);
        $client = new FixtureGraphQlClient(true);
        $provider = $this->provider($client);

        self::assertNull($provider->schema());
        $provider->_resetState();
        $client->failing = false;
        self::assertNull($provider->schema());

        self::assertCount(1, $client->requests);
    }

    /**
     * The provider di.xml describes, introspecting through this client
     *
     * @param FixtureGraphQlClient $client
     * @return CachedSchemaProvider
     */
    private function provider(FixtureGraphQlClient $client): CachedSchemaProvider
    {
        $objectManager = self::objectManager();
        $provider = $objectManager->create(
            CachedSchemaProvider::class,
            ['inner' => $objectManager->create(IntrospectionSchemaProvider::class, ['client' => $client])]
        );
        self::assertInstanceOf(CachedSchemaProvider::class, $provider);

        return $provider;
    }

    /**
     * Names of a type's fields
     *
     * @param IntrospectedSchema $schema
     * @param string $typeName
     * @return list<mixed>
     */
    private function fieldNames(IntrospectedSchema $schema, string $typeName): array
    {
        $fields = ($schema->getType($typeName) ?? [])['fields'] ?? null;
        self::assertIsArray($fields);

        return array_values(
            array_map(static fn (mixed $field): mixed => is_array($field) ? ($field['name'] ?? null) : null, $fields)
        );
    }

    /**
     * Remove every schema entry and every remembered failure from the module's cache type
     *
     * @return void
     */
    private function cleanSchemas(): void
    {
        $cache = self::objectManager()->get(McpCache::class);
        self::assertInstanceOf(McpCache::class, $cache);
        $cache->clean(CacheConstants::CLEANING_MODE_MATCHING_ANY_TAG, [McpCache::SCHEMA_TAG]);
    }

    /**
     * @return StateInterface
     */
    private function cacheState(): StateInterface
    {
        $state = self::objectManager()->get(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);

        return $state;
    }

    /**
     * @return ObjectManager
     */
    private static function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
