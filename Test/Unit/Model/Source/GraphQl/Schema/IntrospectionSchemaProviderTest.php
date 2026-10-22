<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectionSchemaProvider;
use Hryvinskyi\McpServer\Test\Unit\Fake\QueuedGraphQlClient;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The introspection query sent, the schema read from its answer, and every failure as null with a warning
 */
class IntrospectionSchemaProviderTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testSendsTheNamedIntrospectionQueryWithoutVariablesAndReadsTheSchema(): void
    {
        $client = new QueuedGraphQlClient([['data' => ['__schema' => SchemaReaderTest::schemaNode()]]]);

        $schema = $this->provider($client)->schema();

        self::assertInstanceOf(IntrospectedSchema::class, $schema);
        self::assertSame('RootQuery', $schema->getQueryTypeName());
        self::assertCount(1, $client->requests);
        $document = $client->requests[0]['document'];
        self::assertSame([], $client->requests[0]['variables']);
        self::assertStringStartsWith('query IntrospectionQuery {', $document);
        self::assertStringContainsString('queryType { name }', $document);
        self::assertStringContainsString('mutationType { name }', $document);
        self::assertStringContainsString('fields(includeDeprecated: true)', $document);
        self::assertStringContainsString('defaultValue', $document);
        self::assertSame(7, substr_count($document, 'ofType {'));
        self::assertSame([], $this->logger->records);
    }

    public function testATransportFailureGivesNullAndAWarning(): void
    {
        $client = new QueuedGraphQlClient([new GraphQlTransportException('Connection refused')]);

        self::assertNull($this->provider($client)->schema());
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Connection refused', $warnings[0]);
    }

    public function testDisabledIntrospectionAnswersWithErrorsAndGivesNull(): void
    {
        $client = new QueuedGraphQlClient([[
            'errors' => [[
                'message' => 'GraphQL introspection is not allowed, but the query contained __schema or __type',
                'extensions' => ['category' => 'graphql'],
            ]],
        ]]);

        self::assertNull($this->provider($client)->schema());
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('introspection is not allowed', $warnings[0]);
    }

    public function testErrorsBesideDataStillFailTheRead(): void
    {
        $client = new QueuedGraphQlClient([[
            'errors' => [['message' => 'Partial']],
            'data' => ['__schema' => SchemaReaderTest::schemaNode()],
        ]]);

        self::assertNull($this->provider($client)->schema());
        self::assertCount(1, $this->logger->messages('warning'));
    }

    /**
     * @param array<string,mixed> $answer
     */
    #[DataProvider('answersWithoutSchema')]
    public function testAnAnswerWithoutAReadableSchemaGivesNull(array $answer): void
    {
        self::assertNull($this->provider(new QueuedGraphQlClient([$answer]))->schema());
        self::assertCount(1, $this->logger->messages('warning'));
    }

    /**
     * @return array<string,array{array<string,mixed>}>
     */
    public static function answersWithoutSchema(): array
    {
        return [
            'null data' => [['data' => null]],
            'no schema' => [['data' => ['other' => true]]],
            'schema not an object' => [['data' => ['__schema' => 'x']]],
            'no query type' => [['data' => ['__schema' => ['types' => []]]]],
        ];
    }

    /**
     * @param QueuedGraphQlClient $client
     * @return IntrospectionSchemaProvider
     */
    private function provider(QueuedGraphQlClient $client): IntrospectionSchemaProvider
    {
        return new IntrospectionSchemaProvider($client, SchemaReaderTest::reader(), $this->logger);
    }
}
