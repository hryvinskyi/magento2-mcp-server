<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\CachedSchemaProvider;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Test\Unit\Fake\CountingSchemaProvider;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeEndpoint;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemoryCacheFrontend;
use Hryvinskyi\McpServer\Test\Unit\Fake\ManualClock;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * The schema kept in the cache and for the request, and a failed read remembered for five minutes, with the cache
 * type enabled and disabled
 */
class CachedSchemaProviderTest extends TestCase
{
    /**
     * @var IntrospectedSchema
     */
    private IntrospectedSchema $schema;

    /**
     * @var FakeEndpoint
     */
    private FakeEndpoint $endpoint;

    /**
     * @var ManualClock
     */
    private ManualClock $clock;

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $schema = SchemaReaderTest::reader()->read(SchemaReaderTest::schemaNode());
        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        $this->schema = $schema;
        $this->endpoint = new FakeEndpoint();
        $this->clock = new ManualClock();
        $this->logger = new RecordingLogger();
    }

    public function testASchemaIsKeptInTheCacheTaggedAndWithoutLifetime(): void
    {
        $inner = new CountingSchemaProvider($this->schema);
        $cache = new InMemoryCacheFrontend();

        $schema = $this->provider($inner, $cache)->schema();

        self::assertSame($this->schema, $schema);
        self::assertCount(1, $cache->entries);
        $entry = array_values($cache->entries)[0];
        self::assertSame([CachedSchemaProvider::CACHE_TAG], $entry['tags']);
        self::assertNull($entry['lifeTime']);
    }

    public function testAnotherRequestReadsTheSchemaFromTheCache(): void
    {
        $inner = new CountingSchemaProvider($this->schema);
        $cache = new InMemoryCacheFrontend();
        $this->provider($inner, $cache)->schema();

        $schema = $this->provider($inner, $cache)->schema();

        self::assertSame(1, $inner->reads);
        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertSame($this->schema->getTypes(), $schema->getTypes());
        self::assertSame('RootQuery', $schema->getQueryTypeName());
        self::assertSame('RootMutation', $schema->getMutationTypeName());
    }

    public function testTheKeyHoldsTheEndpointUrlAndTheStoreCode(): void
    {
        $inner = new CountingSchemaProvider($this->schema);
        $cache = new InMemoryCacheFrontend();
        $provider = $this->provider($inner, $cache);

        $provider->schema();
        $this->endpoint->storeCode = 'trade';
        $provider->schema();
        $this->endpoint->url = 'http://web/graphql';
        $provider->schema();
        $this->endpoint->storeCode = 'default';
        $this->endpoint->url = 'https://shop.test/graphql';
        $provider->schema();

        self::assertSame(3, $inner->reads);
        self::assertCount(3, $cache->entries);
    }

    public function testWithTheCacheDisabledTheSchemaIsKeptForTheRequestOnly(): void
    {
        $inner = new CountingSchemaProvider($this->schema);
        $provider = $this->provider($inner, new InMemoryCacheFrontend(false));

        $first = $provider->schema();
        $second = $provider->schema();
        $provider->_resetState();
        $third = $provider->schema();

        self::assertSame($this->schema, $first);
        self::assertSame($this->schema, $second);
        self::assertSame($this->schema, $third);
        self::assertSame(2, $inner->reads);
    }

    public function testAFailureIsRememberedInTheCacheForFiveMinutes(): void
    {
        $inner = new CountingSchemaProvider(null);
        $cache = new InMemoryCacheFrontend();

        self::assertNull($this->provider($inner, $cache)->schema());
        self::assertNull($this->provider($inner, $cache)->schema());

        self::assertSame(1, $inner->reads);
        self::assertCount(1, $cache->entries);
        $entry = array_values($cache->entries)[0];
        self::assertSame(300, $entry['lifeTime']);
        self::assertSame([CachedSchemaProvider::CACHE_TAG], $entry['tags']);
    }

    public function testWithTheCacheDisabledAFailureIsRememberedForFiveMinutesOfTheProcess(): void
    {
        $inner = new CountingSchemaProvider(null);
        $provider = $this->provider($inner, new InMemoryCacheFrontend(false));

        self::assertNull($provider->schema());
        $provider->_resetState();
        $this->clock->advanceSeconds(299);
        self::assertNull($provider->schema());
        self::assertSame(1, $inner->reads);

        $this->clock->advanceSeconds(1);
        $inner->schema = $this->schema;
        self::assertSame($this->schema, $provider->schema());
        self::assertSame(2, $inner->reads);
    }

    public function testARememberedFailureBelongsToItsEndpoint(): void
    {
        $inner = new CountingSchemaProvider(null);
        $provider = $this->provider($inner, new InMemoryCacheFrontend(false));
        $provider->schema();

        $this->endpoint->storeCode = 'trade';
        $inner->schema = $this->schema;

        self::assertSame($this->schema, $provider->schema());
        self::assertSame(2, $inner->reads);
    }

    public function testACacheEntryThatIsNoSchemaIsReadAgain(): void
    {
        $inner = new CountingSchemaProvider($this->schema);
        $cache = new InMemoryCacheFrontend();
        $this->provider($inner, $cache)->schema();
        foreach (array_keys($cache->entries) as $key) {
            $cache->entries[$key]['data'] = '{"queryType":{"name":"Gone"},"types":[]}';
        }

        $schema = $this->provider($inner, $cache)->schema();

        self::assertSame($this->schema, $schema);
        self::assertSame(2, $inner->reads);
    }

    public function testAnEndpointThatCannotBeNamedGivesNullAndAWarningWithoutAsking(): void
    {
        $inner = new CountingSchemaProvider($this->schema);
        $this->endpoint->unnamed = true;

        self::assertNull($this->provider($inner, new InMemoryCacheFrontend())->schema());
        self::assertSame(0, $inner->reads);
        self::assertCount(1, $this->logger->messages('warning'));
    }

    /**
     * @param CountingSchemaProvider $inner
     * @param InMemoryCacheFrontend $cache
     * @return CachedSchemaProvider
     */
    private function provider(CountingSchemaProvider $inner, InMemoryCacheFrontend $cache): CachedSchemaProvider
    {
        return new CachedSchemaProvider(
            $inner,
            $cache,
            $this->endpoint,
            SchemaReaderTest::reader(),
            $this->clock,
            $this->logger
        );
    }
}
