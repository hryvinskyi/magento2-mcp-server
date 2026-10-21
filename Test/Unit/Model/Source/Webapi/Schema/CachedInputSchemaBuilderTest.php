<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi\Schema;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\CachedInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchema;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaFactory;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\CountingSchemaBuilder;
use Hryvinskyi\McpServer\Test\Unit\Fake\InMemoryCacheFrontend;
use PHPUnit\Framework\TestCase;

/**
 * The schema cache: one build per ability name, a cached schema identical to a fresh one, a pass-through while the
 * cache type is off, and a fallback schema never kept
 */
class CachedInputSchemaBuilderTest extends TestCase
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @var array<string, mixed>
     */
    private array $schema;

    protected function setUp(): void
    {
        $this->schema = [
            'type' => 'object',
            'properties' => [
                'searchCriteria' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'description' => 'Ünïcode / slashes',
                ],
                'value' => (object) [],
                'ratio' => ['type' => 'number', 'default' => 1.0],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
            ],
            'required' => ['searchCriteria'],
        ];
    }

    public function testTheSecondReadComesFromTheCacheAndEncodesAsTheFirst(): void
    {
        $inner = new CountingSchemaBuilder(null, $this->schema);
        $cache = new InMemoryCacheFrontend();
        $builder = $this->cached($inner, $cache);

        $fresh = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route())->getSchema();
        $cached = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route())->getSchema();

        self::assertSame(1, $inner->builds);
        self::assertSame(json_encode($fresh, self::JSON_FLAGS), json_encode($cached, self::JSON_FLAGS));
        self::assertInstanceOf(\stdClass::class, $this->at($cached, 'properties', 'value'));
        self::assertIsArray($this->at($cached, 'properties', 'searchCriteria'));
    }

    public function testEntriesAreKeyedByAbilityNameAndTagged(): void
    {
        $inner = new CountingSchemaBuilder(null, $this->schema);
        $cache = new InMemoryCacheFrontend();
        $builder = $this->cached($inner, $cache);

        $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());
        $builder->build($this->ability('acme_shop.thing_repository_get.list'), $this->route());

        self::assertSame(2, $inner->builds);
        self::assertCount(2, $cache->entries);
        foreach ($cache->entries as $entry) {
            self::assertSame([CachedInputSchemaBuilder::CACHE_TAG], $entry['tags']);
        }
    }

    public function testWhileTheCacheIsOffEveryReadBuilds(): void
    {
        $inner = new CountingSchemaBuilder(null, $this->schema);
        $builder = $this->cached($inner, new InMemoryCacheFrontend(false));

        $first = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route())->getSchema();
        $second = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route())->getSchema();

        self::assertSame(2, $inner->builds);
        self::assertSame(json_encode($first, self::JSON_FLAGS), json_encode($second, self::JSON_FLAGS));
    }

    public function testAnEntryThatIsNotASchemaIsBuiltAgain(): void
    {
        $inner = new CountingSchemaBuilder(null, $this->schema);
        $cache = new InMemoryCacheFrontend();
        $builder = $this->cached($inner, $cache);
        $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());
        foreach (array_keys($cache->entries) as $key) {
            $cache->entries[$key]['data'] = '{broken';
        }

        $schema = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());

        self::assertSame(2, $inner->builds);
        self::assertSame('object', $schema->getSchema()['type']);
    }

    public function testAFallbackSchemaIsReturnedButNeverKept(): void
    {
        $fallback = ['type' => 'object', 'description' => 'The parameters are not described by the Web API.'];
        $inner = new CountingSchemaBuilder(null, $fallback, true);
        $cache = new InMemoryCacheFrontend();
        $builder = $this->cached($inner, $cache);

        $first = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());
        $second = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());

        self::assertSame($fallback, $first->getSchema());
        self::assertTrue($first->isFallback());
        self::assertSame($fallback, $second->getSchema());
        self::assertSame(2, $inner->builds);
        self::assertSame([], $cache->entries);
    }

    public function testACachedSchemaIsNoFallback(): void
    {
        $builder = $this->cached(new CountingSchemaBuilder(null, $this->schema), new InMemoryCacheFrontend());
        $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());

        $cached = $builder->build($this->ability('acme_shop.thing_repository.get_list'), $this->route());

        self::assertFalse($cached->isFallback());
    }

    /**
     * The cache in front of this builder, with the generated schema factory
     *
     * @param CountingSchemaBuilder $inner
     * @param InMemoryCacheFrontend $cache
     * @return CachedInputSchemaBuilder
     */
    private function cached(CountingSchemaBuilder $inner, InMemoryCacheFrontend $cache): CachedInputSchemaBuilder
    {
        return new CachedInputSchemaBuilder(
            $inner,
            $cache,
            new InputSchemaFactory(new ConstructingObjectManager([InputSchema::class => InputSchema::class]))
        );
    }

    /**
     * The value at a path of keys
     *
     * @param array<mixed> $schema
     * @param string ...$path
     * @return mixed
     */
    private function at(array $schema, string ...$path): mixed
    {
        $node = $schema;
        foreach ($path as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @param string $name
     * @return Ability
     */
    private function ability(string $name): Ability
    {
        return new Ability($name, 'A thing', 'Acme_Shop', 'webapi', 'GET /V1/things', AbilityAccess::Read);
    }

    /**
     * @return Route
     */
    private function route(): Route
    {
        return new Route('/V1/things', 'GET', 'Acme\Shop\Api\ThingRepositoryInterface', 'getList');
    }
}
