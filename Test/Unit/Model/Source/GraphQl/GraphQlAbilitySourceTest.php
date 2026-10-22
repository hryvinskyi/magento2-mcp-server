<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\AnswerMapper;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\FieldCaller;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\OperationBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\SelectionValidator;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlAbilityBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlAbilitySource;
use Hryvinskyi\McpServer\Model\Source\GraphQl\RootFieldLister;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\GraphQlInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\TypeDescriber;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\CountingSchemaProvider;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAuthorization;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeGraphQlConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\GraphQlSchemaKit as Kit;
use Hryvinskyi\McpServer\Test\Unit\Fake\QueuedGraphQlClient;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Authorization;
use PHPUnit\Framework\TestCase;

class GraphQlAbilitySourceTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var CountingSchemaProvider
     */
    private CountingSchemaProvider $schemas;

    /**
     * @var FakeGraphQlConfig
     */
    private FakeGraphQlConfig $config;

    /**
     * @var FakeAuthorization
     */
    private FakeAuthorization $acl;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->config = new FakeGraphQlConfig();
        $this->acl = new FakeAuthorization(['anonymous']);
        $this->schemas = new CountingSchemaProvider(Kit::schema(
            [
                Kit::field('products', Kit::named('OBJECT', 'Products'), [
                    Kit::input('search', Kit::scalar('String')),
                ], 'Search for products.'),
                Kit::field('customer', Kit::named('OBJECT', 'Products')),
            ],
            [Kit::field('createEmptyCart', Kit::scalar('String'))],
            [Kit::objectType('Products', [Kit::field('total_count', Kit::scalar('Int'))])]
        ));
    }

    public function testListsEveryOfferedFieldAndTheTypeDescription(): void
    {
        $this->config->mutationExposed = true;

        $names = array_map(
            static fn (AbilityInterface $ability): string => $ability->getName(),
            $this->abilities($this->source(new QueuedGraphQlClient(), ['customer' => 'query customer']))
        );

        self::assertSame(
            ['graphql.query.products', 'graphql.mutation.create_empty_cart', 'graphql.schema.describe_type'],
            $names
        );
    }

    public function testListsNothingWhileSwitchedOff(): void
    {
        $this->config->enabled = false;

        self::assertSame([], $this->abilities($this->source(new QueuedGraphQlClient())));
    }

    public function testListsNothingWithoutASchema(): void
    {
        $this->schemas->schema = null;

        self::assertSame([], $this->abilities($this->source(new QueuedGraphQlClient())));
    }

    public function testListsOncePerRequest(): void
    {
        $source = $this->source(new QueuedGraphQlClient());

        $this->abilities($source);
        $this->abilities($source);
        self::assertSame(1, $this->schemas->reads);

        $source->_resetState();
        $this->abilities($source);
        self::assertSame(2, $this->schemas->reads);
    }

    public function testTwoFieldsGivingTheSameNameKeepTheFirst(): void
    {
        $this->schemas->schema = Kit::schema([
            Kit::field('fooBar', Kit::scalar('String')),
            Kit::field('foo_bar', Kit::scalar('String')),
        ]);

        $abilities = $this->abilities($this->source(new QueuedGraphQlClient()));

        self::assertSame('query fooBar', $abilities[0]->getSourceReference());
        self::assertCount(2, $abilities);
        self::assertStringContainsString('query foo_bar', $this->logger->messages('warning')[0] ?? '');
    }

    public function testTheInputSchemaComesFromTheFieldsArguments(): void
    {
        $source = $this->source(new QueuedGraphQlClient());

        $schema = $source->inputSchema($this->ability($source, 'graphql.query.products'));

        self::assertSame(['selection'], $schema['required'] ?? null);
        self::assertArrayHasKey('search', is_array($schema['properties']) ? $schema['properties'] : []);
    }

    public function testACallSendsTheOperationAndAnswersTheFieldsValue(): void
    {
        $client = new QueuedGraphQlClient([['data' => ['products' => ['total_count' => 7]]]]);
        $source = $this->source($client);

        $value = $source->invoke(
            $this->ability($source, 'graphql.query.products'),
            ['search' => 'bag', 'selection' => '{ total_count }']
        );

        self::assertSame(['total_count' => 7], $value);
        self::assertSame(
            [[
                'document' => "query(\$search: String) {\n  products(search: \$search)\n{ total_count }\n}",
                'variables' => ['search' => 'bag'],
            ]],
            $client->requests
        );
    }

    public function testAnEndpointThatDoesNotAnswerIsAFailureWithItsDetailsLogged(): void
    {
        $source = $this->source(new QueuedGraphQlClient([new GraphQlTransportException('Connection refused')]));

        try {
            $source->invoke($this->ability($source, 'graphql.query.products'), ['selection' => '{ total_count }']);
            self::fail('The call succeeded.');
        } catch (LocalizedException $exception) {
            self::assertSame('The store\'s GraphQL endpoint did not answer.', $exception->getMessage());
        }

        self::assertStringContainsString('Connection refused', $this->logger->messages('warning')[0] ?? '');
    }

    public function testASmuggledRootFieldIsRefusedBeforeAnythingIsSent(): void
    {
        $client = new QueuedGraphQlClient();
        $source = $this->source($client);

        $this->expectException(LocalizedException::class);
        try {
            $source->invoke(
                $this->ability($source, 'graphql.query.products'),
                ['selection' => '{ total_count } customer { email }']
            );
        } finally {
            self::assertSame([], $client->requests);
        }
    }

    public function testTheTypeDescriptionAnswersFromTheSchemaWithoutACall(): void
    {
        $client = new QueuedGraphQlClient();
        $source = $this->source($client);

        $description = $source->invoke($this->ability($source, 'graphql.schema.describe_type'), ['name' => 'Products']);

        self::assertSame('OBJECT', is_array($description) ? $description['kind'] : null);
        self::assertSame([], $client->requests);
    }

    public function testTheAclAsksForAnonymous(): void
    {
        $source = $this->source(new QueuedGraphQlClient());

        self::assertTrue($source->isAllowed($this->ability($source, 'graphql.query.products')));
        self::assertSame(['anonymous'], $this->acl->asked);
    }

    public function testAnAbilityOfAnotherSourceIsUnknown(): void
    {
        $source = $this->source(new QueuedGraphQlClient());
        $foreign = new Ability(
            'graphql.query.products',
            'Products',
            'GraphQL',
            'webapi',
            'GET /V1/products',
            AbilityAccess::Read,
            ['anonymous']
        );

        $this->expectException(NoSuchEntityException::class);
        $source->invoke($foreign, []);
    }

    /**
     * @param GraphQlAbilitySource $source
     * @return list<AbilityInterface>
     */
    private function abilities(GraphQlAbilitySource $source): array
    {
        $abilities = [];
        foreach ($source->abilities() as $ability) {
            $abilities[] = $ability;
        }

        return $abilities;
    }

    /**
     * @param GraphQlAbilitySource $source
     * @param string $name
     * @return AbilityInterface
     */
    private function ability(GraphQlAbilitySource $source, string $name): AbilityInterface
    {
        foreach ($source->abilities() as $ability) {
            if ($ability->getName() === $name) {
                return $ability;
            }
        }

        self::fail(sprintf('The source does not list %s.', $name));
    }

    /**
     * @param QueuedGraphQlClient $client
     * @param array<string,string> $excludedFields
     * @return GraphQlAbilitySource
     */
    private function source(QueuedGraphQlClient $client, array $excludedFields = []): GraphQlAbilitySource
    {
        $nodes = new NodeReader();

        return new GraphQlAbilitySource(
            new RootFieldLister($this->config, $this->schemas, $nodes, $this->logger),
            new GraphQlAbilityBuilder(
                new AbilityInterfaceFactory(new ConstructingObjectManager([AbilityInterface::class => Ability::class])),
                $nodes,
                $this->logger
            ),
            new GraphQlInputSchemaBuilder($nodes, $this->logger),
            new FieldCaller(
                new OperationBuilder($nodes, new SelectionValidator()),
                $client,
                new AnswerMapper($this->logger),
                $this->logger
            ),
            new TypeDescriber($nodes),
            new Authorization($this->acl),
            $this->logger,
            $excludedFields
        );
    }
}
