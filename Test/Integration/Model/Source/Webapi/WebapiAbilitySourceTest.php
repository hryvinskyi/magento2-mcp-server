<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Source\Webapi;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Api\McpEndpointInterface;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteAbilityBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteFactory;
use Hryvinskyi\McpServer\Model\Source\Webapi\RouteIndex;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\CachedInputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaBuilder;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaBuilderInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiInvoker;
use Hryvinskyi\McpServer\Test\Integration\Fake\FixedServiceLocator;
use Hryvinskyi\McpServer\Test\Integration\Fake\StrictTypedService;
use Hryvinskyi\McpServer\Test\Integration\Fake\TypedServiceInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\AdminRoles;
use Hryvinskyi\McpServer\Test\Unit\Fake\CountingSchemaBuilder;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeWebapiConfig;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Authorization as FrameworkAuthorization;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Authorization as WebapiAuthorization;
use Magento\Integration\Api\AdminTokenServiceInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\Webapi\Model\ConfigInterface;
use Magento\Webapi\Model\WebapiRoleLocator;
use PHPUnit\Framework\TestCase;

/**
 * The Web API ability source as the container wires it in the REST area, over the store's real `webapi.xml` routes
 */
#[AppArea('webapi_rest')]
class WebapiAbilitySourceTest extends TestCase
{
    private const GET_LIST = 'catalog.product_repository.get_list';
    private const SAVE = 'catalog.product_repository.save';
    private const GET = 'catalog.product_repository.get';
    private const COUNTRIES = 'directory.country_information_acquirer.get_countries_info';
    private const TOKEN_ISSUER = 'integration.admin_token_service.create_admin_access_token';
    private const ATTRIBUTE_SET_SAVE = 'catalog.attribute_set_repository.save';
    private const ATTRIBUTE_SET_CREATE = 'catalog.attribute_set_management.create';
    private const ATTRIBUTE_SET_LIST = 'catalog.attribute_set_repository.get_list';

    /**
     * Shared instances that hold a user context, built again for each caller a test plays
     */
    private const CALLER_BOUND = [
        WebapiRoleLocator::class,
        FrameworkAuthorization::class,
        WebapiAuthorization::class,
        WebapiAbilitySource::class,
        AbilityCatalog::class,
    ];

    protected function tearDown(): void
    {
        $objectManager = self::objectManager();
        $objectManager->removeSharedInstance(UserContextInterface::class, true);
        foreach (self::CALLER_BOUND as $class) {
            $objectManager->removeSharedInstance($class);
        }
        $this->catalog()->_resetState();
    }

    public function testTheCatalogListsTheProductRepositoryWithItsAccessAndCategory(): void
    {
        $catalog = $this->catalog();
        $list = $catalog->get(self::GET_LIST);
        $save = $catalog->get(self::SAVE);

        self::assertSame(WebapiAbilitySource::CODE, $list->getSource());
        self::assertSame(AbilityAccess::Read, $list->getAccess());
        self::assertSame('Magento_Catalog', $list->getCategory());
        self::assertSame('GET /V1/products', $list->getSourceReference());
        self::assertNotSame('', $list->getTitle());
        self::assertSame(AbilityAccess::Write, $save->getAccess());
        self::assertSame('POST /V1/products', $save->getSourceReference());
    }

    public function testTheServiceFormWithAVersionSegmentIsListed(): void
    {
        $ability = $this->catalog()->get('backend.module_service.get_modules');

        self::assertSame('Magento_Backend', $ability->getCategory());
    }

    public function testNoAbilityComesFromASelfRoute(): void
    {
        $selfRoutes = $this->selfRouteReferences();
        self::assertNotEmpty($selfRoutes, 'The store declares no self route to check against.');

        $total = $this->catalog()->search('', null, null, 100, 0)->getTotal();
        $references = [];
        for ($offset = 0; $offset < $total; $offset += 100) {
            foreach ($this->catalog()->search('', null, null, 100, $offset)->getItems() as $ability) {
                $references[] = $ability->getSourceReference();
            }
        }

        self::assertCount($total, $references);
        self::assertSame([], array_values(array_intersect($references, $selfRoutes)));
    }

    public function testTheModuleEndpointIsNeverAnAbility(): void
    {
        $config = new FakeWebapiConfig([
            '/V1/mcp' => ['POST' => FakeWebapiConfig::route(
                McpEndpointInterface::class,
                'handle',
                ['Hryvinskyi_McpServer::access']
            )],
            '/V1/products' => ['GET' => FakeWebapiConfig::route(
                'Magento\Catalog\Api\ProductRepositoryInterface',
                'getList'
            )],
        ]);
        $index = self::objectManager()->create(RouteIndex::class, ['config' => $config]);
        self::assertInstanceOf(RouteIndex::class, $index);

        self::assertSame(
            ['GET /V1/products'],
            array_map(static fn (Route $route): string => $route->getReference(), $index->routes())
        );
        $this->expectException(NoSuchEntityException::class);
        $this->catalog()->get('hryvinskyi_mcp_server.mcp_endpoint.handle');
    }

    #[DbIsolation(true)]
    public function testTheAclAsksForTheRouteResourcesOfTheCaller(): void
    {
        $list = $this->catalog()->get(self::GET_LIST);
        self::assertSame(['Magento_Catalog::products'], $list->getAclResources());

        $this->actAs(AdminRoles::grant(['Magento_Backend::all'], 990001));
        self::assertTrue($this->catalog()->isAllowed($this->catalog()->get(self::GET_LIST)));
        self::assertTrue($this->catalog()->isAllowed($this->catalog()->get(self::COUNTRIES)));

        $this->actAs(AdminRoles::grant(['Magento_Backend::stores'], 990002));
        self::assertFalse($this->catalog()->isAllowed($this->catalog()->get(self::GET_LIST)));
        self::assertTrue($this->catalog()->isAllowed($this->catalog()->get(self::COUNTRIES)));
        self::assertSame(['anonymous'], $this->catalog()->get(self::COUNTRIES)->getAclResources());
    }

    public function testTheSchemaOfGetListRequiresSearchCriteria(): void
    {
        $catalog = $this->catalog();
        $schema = $catalog->schemaOf($catalog->get(self::GET_LIST));

        self::assertSame('object', $schema['type']);
        self::assertSame(['searchCriteria'], $schema['required'] ?? null);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        $criteria = $properties['searchCriteria'] ?? null;
        self::assertIsArray($criteria);
        $criteriaFields = $criteria['properties'] ?? null;
        self::assertIsArray($criteriaFields);
        self::assertArrayHasKey('filter_groups', $criteriaFields);
        self::assertArrayHasKey('page_size', $criteriaFields);
        self::assertSame([], $this->requiredListsIn($criteria), 'Nothing inside the search criteria is required.');
    }

    public function testTheSchemaOfSaveRequiresTheProductAndNothingInsideIt(): void
    {
        $catalog = $this->catalog();
        $schema = $catalog->schemaOf($catalog->get(self::SAVE));

        self::assertSame(['product'], $schema['required'] ?? null);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        $product = $properties['product'] ?? null;
        self::assertIsArray($product);
        $productFields = $product['properties'] ?? null;
        self::assertIsArray($productFields);
        self::assertArrayHasKey('sku', $productFields);
        self::assertSame([], $this->requiredListsIn($product), 'Nothing inside the product is required.');
    }

    public function testAnUpdateRouteTakesTheRecordIdOfItsPathAsAnOptionalArgument(): void
    {
        $catalog = $this->catalog();
        $ability = $catalog->get(self::ATTRIBUTE_SET_SAVE);
        $schema = $catalog->schemaOf($ability);

        self::assertSame('PUT /V1/products/attribute-sets/:attributeSetId', $ability->getSourceReference());
        self::assertSame(['attributeSet'], $schema['required'] ?? null);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['attributeSet', 'attributeSetId'], array_keys($properties));
    }

    /**
     * The attribute sets are created and read through abilities too, so the test needs no class of the catalog
     */
    #[DbIsolation(true)]
    public function testTheRecordIdOfTheUpdateRouteUpdatesThatRecordInsteadOfCreatingOne(): void
    {
        $default = $this->attributeSetsNamed('Default')[0] ?? null;
        self::assertIsArray($default);
        $created = $this->call(self::ATTRIBUTE_SET_CREATE, [
            'attributeSet' => [
                'attribute_set_name' => 'MCP path id, before',
                'entity_type_id' => $default['entity_type_id'],
            ],
            'skeletonId' => $default['attribute_set_id'],
        ]);
        $id = $created['attribute_set_id'] ?? null;
        self::assertIsNumeric($id);

        $saved = $this->call(self::ATTRIBUTE_SET_SAVE, [
            'attributeSetId' => $id,
            'attributeSet' => [
                'attribute_set_name' => 'MCP path id, after',
                'entity_type_id' => $default['entity_type_id'],
            ],
        ]);

        self::assertEquals($id, $saved['attribute_set_id'] ?? null);
        self::assertSame([], $this->attributeSetsNamed('MCP path id, before'));
        $renamed = $this->attributeSetsNamed('MCP path id, after');
        self::assertCount(1, $renamed, 'The set was renamed, and no second set was created.');
        self::assertEquals($id, $renamed[0]['attribute_set_id'] ?? null);
    }

    public function testTheSchemaOfAMethodWithoutParametersEncodesItsPropertiesAsAnObject(): void
    {
        $catalog = $this->catalog();
        $schema = $catalog->schemaOf($catalog->get(self::COUNTRIES));

        self::assertSame('{"type":"object","properties":{}}', json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function testInvokingTheCountriesAbilityReturnsTheCountries(): void
    {
        $result = $this->sourceOf($this->catalog())->invoke($this->catalog()->get(self::COUNTRIES), []);

        self::assertIsArray($result);
        $ids = array_map(
            static fn (mixed $country): mixed => is_array($country) ? ($country['id'] ?? null) : null,
            $result
        );
        self::assertContains('US', $ids);
    }

    public function testInvokingGetWithAnUnknownSkuFailsWithTheRepositorysNotFoundException(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('hryvinskyi-mcp-no-such-sku');

        $this->sourceOf($this->catalog())->invoke(
            $this->catalog()->get(self::GET),
            ['sku' => 'hryvinskyi-mcp-no-such-sku']
        );
    }

    public function testArgumentsTheServiceRefusesByTypeFailWithALocalizedException(): void
    {
        $invoker = self::objectManager()->create(
            WebapiInvoker::class,
            ['serviceLocator' => new FixedServiceLocator(new StrictTypedService())]
        );
        self::assertInstanceOf(WebapiInvoker::class, $invoker);
        $factory = self::objectManager()->get(RouteFactory::class);
        self::assertInstanceOf(RouteFactory::class, $factory);
        $route = $factory->create([
            'url' => '/V1/hryvinskyi-mcp-test/double',
            'httpMethod' => 'POST',
            'serviceClass' => TypedServiceInterface::class,
            'serviceMethod' => 'double',
            'aclResources' => ['anonymous'],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The arguments do not match the parameters of the ability');

        $invoker->invoke($this->catalog()->get(self::COUNTRIES), $route, ['value' => 'not a number']);
    }

    public function testTheBuiltInDenyListDeniesTheAdminTokenIssuerOfTheIntegrationModule(): void
    {
        $builder = self::objectManager()->get(RouteAbilityBuilder::class);
        self::assertInstanceOf(RouteAbilityBuilder::class, $builder);
        $factory = self::objectManager()->get(RouteFactory::class);
        self::assertInstanceOf(RouteFactory::class, $factory);

        $ability = $builder->build(
            $factory->create([
                'url' => '/V1/integration/admin/token',
                'httpMethod' => 'POST',
                'serviceClass' => AdminTokenServiceInterface::class,
                'serviceMethod' => 'createAdminAccessToken',
                'aclResources' => ['anonymous'],
            ]),
            WebapiAbilitySource::CODE
        );

        self::assertInstanceOf(AbilityInterface::class, $ability);
        self::assertSame(self::TOKEN_ISSUER, $ability->getName());
        self::assertSame(Exposure::Denied, $this->policy()->exposureOf($ability));
    }

    /**
     * The admin token route calls the integration module's service, or the service of a module that takes the route
     * over; whichever it is on this store, its ability is denied, and so is the customer token issuer
     */
    public function testTheBuiltInDenyListDeniesTheTokenIssuersThisStoreRoutes(): void
    {
        $adminIssuer = null;
        $page = $this->catalog()->search('token', null, AbilityAccess::Write, 100, 0);
        foreach ($page->getItems() as $ability) {
            if ($ability->getSourceReference() === 'POST /V1/integration/admin/token') {
                $adminIssuer = $ability;
            }
        }

        self::assertInstanceOf(AbilityInterface::class, $adminIssuer);
        self::assertStringEndsWith('.admin_token_service.create_admin_access_token', $adminIssuer->getName());
        self::assertSame(Exposure::Denied, $this->policy()->exposureOf($adminIssuer));
        self::assertSame(
            Exposure::Denied,
            $this->policy()->exposureOf(
                $this->catalog()->get('integration.customer_token_service.create_customer_access_token')
            )
        );
    }

    public function testTheSchemaBuilderIsTheCacheInFrontOfTheMetadataBuilder(): void
    {
        $builder = self::objectManager()->get(InputSchemaBuilderInterface::class);

        self::assertInstanceOf(CachedInputSchemaBuilder::class, $builder);
    }

    public function testTheSecondSchemaReadComesFromTheCache(): void
    {
        $state = self::objectManager()->get(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);
        $wasEnabled = $state->isEnabled(McpCache::TYPE_IDENTIFIER);
        $state->setEnabled(McpCache::TYPE_IDENTIFIER, true);

        try {
            $cache = self::objectManager()->get(McpCache::class);
            self::assertInstanceOf(McpCache::class, $cache);
            $cache->clean();
            $metadataBuilder = self::objectManager()->get(InputSchemaBuilder::class);
            self::assertInstanceOf(InputSchemaBuilder::class, $metadataBuilder);
            $counting = new CountingSchemaBuilder($metadataBuilder);
            $cached = self::objectManager()->create(CachedInputSchemaBuilder::class, ['inner' => $counting]);
            self::assertInstanceOf(CachedInputSchemaBuilder::class, $cached);
            $ability = $this->catalog()->get(self::GET_LIST);
            $route = $this->routeOf($ability);

            $first = $cached->build($ability, $route)->getSchema();
            $second = $cached->build($ability, $route)->getSchema();

            self::assertSame(1, $counting->builds);
            self::assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));
        } finally {
            $state->setEnabled(McpCache::TYPE_IDENTIFIER, $wasEnabled);
        }
    }

    /**
     * Every `required` list in a schema, at any depth, as the path where it was found
     *
     * A field that happens to be named `required` is a schema, not a list, and does not count.
     *
     * @param array<mixed> $schema
     * @param string $path
     * @return list<string>
     */
    private function requiredListsIn(array $schema, string $path = ''): array
    {
        $required = $schema['required'] ?? null;
        $found = is_array($required) && $required !== [] && array_is_list($required) ? [$path] : [];
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $found = [...$found, ...$this->requiredListsIn($value, $path . '/' . $key)];
            }
        }

        return $found;
    }

    /**
     * Run an ability of the Web API source and return its output, which is an object
     *
     * @param string $name
     * @param array<string, mixed> $arguments
     * @return array<mixed>
     */
    private function call(string $name, array $arguments): array
    {
        $output = $this->sourceOf($this->catalog())->invoke($this->catalog()->get($name), $arguments);
        self::assertIsArray($output);

        return $output;
    }

    /**
     * The product attribute sets with this name, as the attribute set list ability returns them
     *
     * @param string $name
     * @return list<array<mixed>>
     */
    private function attributeSetsNamed(string $name): array
    {
        $found = $this->call(self::ATTRIBUTE_SET_LIST, ['searchCriteria' => ['filter_groups' => [['filters' => [
            ['field' => 'attribute_set_name', 'value' => $name, 'condition_type' => 'eq'],
        ]]]]]);
        $items = $found['items'] ?? null;
        self::assertIsArray($items);

        $sets = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            $sets[] = $item;
        }

        return $sets;
    }

    /**
     * Play this caller from now on: every shared object that holds a user context is built again
     *
     * @param UserContextInterface $caller
     * @return void
     */
    private function actAs(UserContextInterface $caller): void
    {
        $objectManager = self::objectManager();
        foreach (self::CALLER_BOUND as $class) {
            $objectManager->removeSharedInstance($class);
        }
        $objectManager->addSharedInstance($caller, UserContextInterface::class, true);
        $aclBuilder = $objectManager->get(AclBuilder::class);
        self::assertInstanceOf(AclBuilder::class, $aclBuilder);
        $aclBuilder->resetRuntimeAcl();
    }

    /**
     * `<HTTP method> <URL>` of every route that needs the resource `self`
     *
     * @return list<string>
     */
    private function selfRouteReferences(): array
    {
        $config = self::objectManager()->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $config);
        $routes = $config->getServices()['routes'] ?? [];
        self::assertIsArray($routes);

        $references = [];
        foreach ($routes as $url => $byMethod) {
            self::assertIsArray($byMethod);
            foreach ($byMethod as $httpMethod => $route) {
                self::assertIsArray($route);
                $resources = $route['resources'] ?? [];
                self::assertIsArray($resources);
                if (array_key_exists('self', $resources)) {
                    $references[] = $httpMethod . ' ' . $url;
                }
            }
        }

        return $references;
    }

    /**
     * The route of a listed ability, as the wired route index gives it
     *
     * @param AbilityInterface $ability
     * @return Route
     */
    private function routeOf(AbilityInterface $ability): Route
    {
        $index = self::objectManager()->get(RouteIndex::class);
        self::assertInstanceOf(RouteIndex::class, $index);
        foreach ($index->routes() as $route) {
            if ($route->getReference() === $ability->getSourceReference()) {
                return $route;
            }
        }

        self::fail(sprintf('No route %s.', $ability->getSourceReference()));
    }

    /**
     * The Web API source of the catalog
     *
     * @param AbilityCatalog $catalog
     * @return WebapiAbilitySource
     */
    private function sourceOf(AbilityCatalog $catalog): WebapiAbilitySource
    {
        $source = $catalog->sourceOf($catalog->get(self::COUNTRIES));
        self::assertInstanceOf(WebapiAbilitySource::class, $source);

        return $source;
    }

    /**
     * The exposure policy the container wires
     *
     * @return ExposurePolicyInterface
     */
    private function policy(): ExposurePolicyInterface
    {
        $policy = self::objectManager()->get(ExposurePolicyInterface::class);
        self::assertInstanceOf(ExposurePolicyInterface::class, $policy);

        return $policy;
    }

    /**
     * The catalog the container wires
     *
     * @return AbilityCatalog
     */
    private function catalog(): AbilityCatalog
    {
        $catalog = self::objectManager()->get(AbilityCatalogInterface::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        return $catalog;
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
