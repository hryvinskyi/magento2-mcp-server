<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\AbilityInvokerInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\AbilityOverrideRepository;
use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Config;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\HttpGraphQlClient;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlAbilitySource;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfig;
use Hryvinskyi\McpServer\Test\Integration\Fake\FixtureGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fake\GraphQlServingCurlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\NamesGraphQlEndpoint;
use Hryvinskyi\McpServer\Test\Integration\Fixture\McpAcl;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Authorization as FrameworkAuthorization;
use Magento\Framework\Cache\CacheConstants;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Webapi\Authorization as WebapiAuthorization;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\Webapi\Model\WebapiRoleLocator;
use PHPUnit\Framework\TestCase;

/**
 * The GraphQL source through the catalog and the invoker the container builds from di.xml, as a guest in the REST
 * area, over a client that answers introspection from a store's stored schema and every other operation from the
 * answers a test queues
 */
#[AppArea('webapi_rest')]
class GraphQlAbilitySourceTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use NamesGraphQlEndpoint;

    private const PRODUCTS = 'graphql.query.products';
    private const CREATE_EMPTY_CART = 'graphql.mutation.create_empty_cart';
    private const DESCRIBE_TYPE = 'graphql.schema.describe_type';
    private const SELECTION = '{ items { sku name } total_count }';
    private const STORE_VIEW = 'mcp_graphql_view';

    /**
     * Shared instances that hold the catalog, the client or a user context, built again for each test
     */
    private const REBUILT = [
        WebapiRoleLocator::class,
        FrameworkAuthorization::class,
        WebapiAuthorization::class,
        McpAcl::AUTHORIZATION,
        McpAcl::WEBAPI_AUTHORIZATION,
        GraphQlAbilitySource::class,
        AbilityCatalog::class,
        AbilityInvoker::class,
    ];

    /**
     * @var FixtureGraphQlClient
     */
    private FixtureGraphQlClient $graphQl;

    protected function setUp(): void
    {
        $this->cleanSchemas();
        $this->forgetSharedInstances();
        $repository = self::objectManager()->get(AbilityOverrideRepository::class);
        self::assertInstanceOf(AbilityOverrideRepository::class, $repository);
        $repository->_resetState();
        $this->graphQl = new FixtureGraphQlClient();
        $this->useGraphQlClient($this->graphQl);
    }

    protected function tearDown(): void
    {
        $this->forgetSharedInstances();
        $this->cleanSchemas();
    }

    public function testProductsIsAGuestReadAbilityDeniedUntilAnonymousAbilitiesAreExposed(): void
    {
        $ability = $this->catalog()->get(self::PRODUCTS);
        $result = $this->invoker()->invoke($this->invocation(self::PRODUCTS, ['selection' => self::SELECTION]));

        self::assertSame(GraphQlAbilitySource::CODE, $ability->getSource());
        self::assertSame('GraphQL', $ability->getCategory());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame('query products', $ability->getSourceReference());
        self::assertSame(['anonymous'], $ability->getAclResources());
        self::assertSame(InvocationOutcome::Denied, $result->getOutcome());
        self::assertSame(InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY, $result->getDenialReason());
        self::assertSame(['introspection'], $this->requestKinds());
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testWithAnonymousAbilitiesExposedACallSendsOneOperationWithTheArgumentsAsVariables(): void
    {
        $this->graphQl->answers[] = ['data' => ['products' => ['items' => [], 'total_count' => 0]]];

        $result = $this->invoker()->invoke($this->invocation(self::PRODUCTS, [
            'search' => 'bag',
            'pageSize' => 2,
            'filter' => ['sku' => ['eq' => 'MB01']],
            'selection' => self::SELECTION,
        ]));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome(), $result->getMessage());
        self::assertSame(['items' => [], 'total_count' => 0], $result->getValue());
        self::assertSame(['introspection', 'operation'], $this->requestKinds());
        self::assertSame(
            "query(\$search: String, \$filter: ProductAttributeFilterInput, \$pageSize: Int) {\n"
            . "  products(search: \$search, filter: \$filter, pageSize: \$pageSize)\n"
            . self::SELECTION . "\n}",
            $this->graphQl->requests[1]['document']
        );
        self::assertSame(
            ['search' => 'bag', 'filter' => ['sku' => ['eq' => 'MB01']], 'pageSize' => 2],
            $this->graphQl->requests[1]['variables']
        );
    }

    public function testTheInputSchemaTakesTheArgumentsAndASelection(): void
    {
        $schema = $this->catalog()->schemaOf($this->catalog()->get(self::PRODUCTS));

        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['search', 'filter', 'pageSize', 'currentPage', 'sort', 'selection'], array_keys($properties));
        self::assertSame(['selection'], $schema['required'] ?? null);
    }

    public function testMutationsAreNotListedWhileTheyAreNotExposed(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->catalog()->get(self::CREATE_EMPTY_CART);
    }

    #[ConfigFixture(GraphQlConfig::XML_PATH_EXPOSE_MUTATIONS, '1')]
    public function testExposedMutationsAreListedAsWrites(): void
    {
        $ability = $this->catalog()->get(self::CREATE_EMPTY_CART);

        self::assertSame(AbilityAccess::Write, $ability->getAccess());
        self::assertSame('mutation createEmptyCart', $ability->getSourceReference());
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testDescribeTypeAnswersFromTheSchemaWithoutAnotherRequest(): void
    {
        $result = $this->invoker()->invoke($this->invocation(self::DESCRIBE_TYPE, ['name' => 'Products']));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome(), $result->getMessage());
        $description = $result->getValue();
        self::assertIsArray($description);
        self::assertSame('OBJECT', $description['kind'] ?? null);
        self::assertIsArray($description['fields'] ?? null);
        self::assertContains('total_count', array_column($description['fields'], 'name'));
        self::assertSame(['introspection'], $this->requestKinds());
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testAGraphqlErrorIsAFailedResultCarryingItsMessageOnly(): void
    {
        $this->graphQl->answers[] = [
            'errors' => [[
                'message' => '\'search\' or \'filter\' input argument is required.',
                'locations' => [['line' => 2, 'column' => 3]],
                'path' => ['products'],
                'extensions' => ['category' => 'graphql-input', 'debugMessage' => 'trace that stays in the log'],
            ]],
            'data' => ['products' => null],
        ];

        $result = $this->invoker()->invoke($this->invocation(self::PRODUCTS, ['selection' => self::SELECTION]));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertSame(
            'The GraphQL query failed: \'search\' or \'filter\' input argument is required.',
            $result->getMessage()
        );
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testASelectionThatSmugglesASecondRootFieldIsRefusedBeforeTheEndpointIsAsked(): void
    {
        $result = $this->invoker()->invoke($this->invocation(self::PRODUCTS, [
            'search' => 'bag',
            'selection' => '{ total_count } countries { id }',
        ]));

        self::assertSame(InvocationOutcome::Failed, $result->getOutcome());
        self::assertStringContainsString('exactly one GraphQL selection set', $result->getMessage());
        self::assertSame(['introspection'], $this->requestKinds());
    }

    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    #[ConfigFixture(GraphQlConfig::XML_PATH_EXPOSE_MUTATIONS, '1')]
    public function testTokenAndPasswordMutationsAreAlwaysDenied(): void
    {
        $this->useGraphQlClient($this->graphQl = new FixtureGraphQlClient(false, ['mutation' => [
            $this->stringField('generateCustomerToken', ['email', 'password']),
            $this->stringField('revokeCustomerToken', []),
            $this->stringField('deletePaymentToken', ['public_hash']),
            $this->stringField('resetPassword', ['email', 'resetPasswordToken', 'newPassword']),
        ]]));

        $calls = [
            'graphql.mutation.generate_customer_token' => ['email' => 'roni_cost@example.com', 'password' => 'secret'],
            'graphql.mutation.revoke_customer_token' => [],
            'graphql.mutation.delete_payment_token' => ['public_hash' => 'a-stored-card'],
            'graphql.mutation.reset_password' => ['email' => 'roni_cost@example.com', 'newPassword' => 'secret'],
        ];
        foreach ($calls as $name => $arguments) {
            $result = $this->invoker()->invoke($this->invocation($name, $arguments));

            self::assertSame(InvocationOutcome::Denied, $result->getOutcome(), $name);
            self::assertSame(InvocationResultInterface::DENIAL_DENY_LIST, $result->getDenialReason(), $name);
        }
        self::assertSame(['introspection'], $this->requestKinds());

        $this->graphQl->answers[] = ['data' => ['createEmptyCart' => 'cart-id']];
        $control = $this->invoker()->invoke($this->invocation(self::CREATE_EMPTY_CART));
        self::assertSame(
            InvocationOutcome::Succeeded,
            $control->getOutcome(),
            'Another mutation runs under the same settings, so the denials above come from the deny-list alone.'
        );
        self::assertSame('cart-id', $control->getValue());
    }

    public function testTheShippedExclusionsLeaveOutQueryFieldsThatAnswerOnlyASignedInCustomer(): void
    {
        $this->useGraphQlClient($this->graphQl = new FixtureGraphQlClient(false, ['query' => [
            $this->stringField('customerCart', []),
            $this->stringField('storeConfigCode', []),
        ]]));

        self::assertSame(
            'query storeConfigCode',
            $this->catalog()->get('graphql.query.store_config_code')->getSourceReference()
        );
        $this->expectException(NoSuchEntityException::class);
        $this->catalog()->get('graphql.query.customer_cart');
    }

    public function testAnExcludedFieldIsNotListed(): void
    {
        $source = self::objectManager()->create(
            GraphQlAbilitySource::class,
            ['excludedFields' => ['countries' => 'query countries']]
        );
        self::assertInstanceOf(GraphQlAbilitySource::class, $source);
        self::objectManager()->addSharedInstance($source, GraphQlAbilitySource::class);
        self::objectManager()->removeSharedInstance(AbilityCatalog::class);

        self::assertSame(GraphQlAbilitySource::CODE, $this->catalog()->get(self::PRODUCTS)->getSource());
        $this->expectException(NoSuchEntityException::class);
        $this->catalog()->get('graphql.query.countries');
    }

    #[DbIsolation(false)]
    #[DataFixture(StoreFixture::class, ['code' => self::STORE_VIEW])]
    #[ConfigFixture(GraphQlConfig::XML_PATH_STORE_CODE, self::STORE_VIEW)]
    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testTheChosenStoreViewIsSentAsTheStoreHeader(): void
    {
        $curl = new GraphQlServingCurlClient($this->graphQl);
        $clientFactory = $this->createStub(ClientFactory::class);
        $clientFactory->method('create')->willReturn($curl);
        $http = self::objectManager()->create(HttpGraphQlClient::class, ['clientFactory' => $clientFactory]);
        self::assertInstanceOf(HttpGraphQlClient::class, $http);
        $this->useGraphQlClient($http);
        $this->graphQl->answers[] = ['data' => ['products' => ['total_count' => 0]]];

        $result = $this->invoker()->invoke($this->invocation(self::PRODUCTS, [
            'search' => 'bag',
            'selection' => '{ total_count }',
        ]));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome(), $result->getMessage());
        self::assertCount(2, $curl->posts);
        foreach ($curl->posts as $post) {
            self::assertSame(self::STORE_VIEW, $post['headers']['Store'] ?? null);
            self::assertArrayNotHasKey('Authorization', $post['headers']);
        }
    }

    /**
     * A root field returning a string, taking these non-null string arguments
     *
     * @param string $name
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function stringField(string $name, array $arguments): array
    {
        $string = ['kind' => 'SCALAR', 'name' => 'String', 'ofType' => null];

        return [
            'name' => $name,
            'description' => '',
            'args' => array_map(
                static fn (string $argument): array => [
                    'name' => $argument,
                    'description' => '',
                    'type' => ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $string],
                    'defaultValue' => null,
                ],
                $arguments
            ),
            'type' => $string,
            'isDeprecated' => false,
            'deprecationReason' => null,
        ];
    }

    /**
     * What each request the fake client received was: the introspection, or an operation
     *
     * @return list<string>
     */
    private function requestKinds(): array
    {
        return array_map(
            static fn (array $request): string => str_contains($request['document'], '__schema')
                ? 'introspection'
                : 'operation',
            $this->graphQl->requests
        );
    }

    /**
     * @param string $name
     * @param array<string,mixed> $arguments
     * @return InvocationInterface
     */
    private function invocation(string $name, array $arguments = []): InvocationInterface
    {
        $objectManager = self::objectManager();
        $callers = $objectManager->get(CallerInterfaceFactory::class);
        self::assertInstanceOf(CallerInterfaceFactory::class, $callers);
        $clients = $objectManager->get(ClientInterfaceFactory::class);
        self::assertInstanceOf(ClientInterfaceFactory::class, $clients);
        $invocations = $objectManager->get(InvocationInterfaceFactory::class);
        self::assertInstanceOf(InvocationInterfaceFactory::class, $invocations);

        return $invocations->create([
            'abilityName' => $name,
            'caller' => $callers->create(['userType' => UserContextInterface::USER_TYPE_GUEST, 'userId' => 0]),
            'client' => $clients->create(['name' => 'integration test', 'version' => '1.0']),
            'transport' => 'http',
            'confirmed' => false,
        ])->withArguments($arguments);
    }

    /**
     * @return AbilityCatalogInterface
     */
    private function catalog(): AbilityCatalogInterface
    {
        $catalog = self::objectManager()->get(AbilityCatalogInterface::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        return $catalog;
    }

    /**
     * @return AbilityInvokerInterface
     */
    private function invoker(): AbilityInvokerInterface
    {
        $invoker = self::objectManager()->get(AbilityInvokerInterface::class);
        self::assertInstanceOf(AbilityInvoker::class, $invoker);

        return $invoker;
    }

    /**
     * @return void
     */
    private function forgetSharedInstances(): void
    {
        foreach (self::REBUILT as $class) {
            self::objectManager()->removeSharedInstance($class);
        }
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
     * @return ObjectManager
     */
    private static function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
