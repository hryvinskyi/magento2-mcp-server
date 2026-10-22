<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Operation;
use Hryvinskyi\McpServer\Model\Source\GraphQl\RootFieldLister;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\CountingSchemaProvider;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeGraphQlConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\GraphQlSchemaKit as Kit;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

class RootFieldListerTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var CountingSchemaProvider
     */
    private CountingSchemaProvider $schemas;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->schemas = new CountingSchemaProvider(Kit::schema(
            [
                Kit::field('products', Kit::scalar('String')),
                Kit::field('customer', Kit::scalar('String')),
                Kit::field('countries', Kit::scalar('String')),
            ],
            [Kit::field('createEmptyCart', Kit::scalar('String'))]
        ));
    }

    public function testSwitchedOffNothingIsOfferedAndTheSchemaIsNotRead(): void
    {
        self::assertNull($this->lister(new FakeGraphQlConfig(false))->exposedFields([]));
        self::assertSame(0, $this->schemas->reads);
    }

    public function testWithoutASchemaNothingIsOffered(): void
    {
        $this->schemas->schema = null;

        self::assertNull($this->lister(new FakeGraphQlConfig())->exposedFields([]));
    }

    public function testQueryFieldsAreOfferedAndMutationsOnlyWhileExposed(): void
    {
        self::assertSame(
            ['query products', 'query customer', 'query countries'],
            $this->references($this->lister(new FakeGraphQlConfig())->exposedFields([]))
        );
        self::assertSame(
            ['query products', 'query customer', 'query countries', 'mutation createEmptyCart'],
            $this->references($this->lister(new FakeGraphQlConfig(true, true))->exposedFields([]))
        );
    }

    public function testExcludedFieldsAreLeftOutSilently(): void
    {
        $exposed = $this->lister(new FakeGraphQlConfig(true, true))
            ->exposedFields(['query customer', 'mutation createEmptyCart', 'mutation products']);

        self::assertSame(['query products', 'query countries'], $this->references($exposed));
        self::assertSame([], $this->logger->records);
    }

    public function testAFieldWithAnArgumentNamedSelectionIsSkippedWithAWarning(): void
    {
        $this->schemas->schema = Kit::schema([
            Kit::field('search', Kit::scalar('String'), [Kit::input('selection', Kit::scalar('String'))]),
            Kit::field('products', Kit::scalar('String')),
        ]);

        self::assertSame(
            ['query products'],
            $this->references($this->lister(new FakeGraphQlConfig())->exposedFields([]))
        );
        self::assertStringContainsString('query search', $this->logger->messages('warning')[0] ?? '');
    }

    /**
     * @param array{fields: list<array{operation: Operation,field: array{name: string}}>}|null $exposed
     * @return list<string>
     */
    private function references(?array $exposed): array
    {
        self::assertNotNull($exposed);

        return array_map(
            static fn (array $entry): string => $entry['operation']->value . ' ' . $entry['field']['name'],
            $exposed['fields']
        );
    }

    /**
     * @param FakeGraphQlConfig $config
     * @return RootFieldLister
     */
    private function lister(FakeGraphQlConfig $config): RootFieldLister
    {
        return new RootFieldLister($config, $this->schemas, new NodeReader(), $this->logger);
    }
}
