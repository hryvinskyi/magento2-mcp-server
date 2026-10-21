<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Http;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Model\Config;
use Hryvinskyi\McpServer\Test\Integration\Fixture\AdminRoles;
use Hryvinskyi\McpServer\Test\Integration\Http\McpEndpointClient;
use Laminas\Http\Header\HeaderInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\Integration\Api\Data\UserTokenParametersInterfaceFactory;
use Magento\Integration\Api\UserTokenIssuerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The MCP endpoint end to end: a request goes through Magento's REST front controller, which authenticates the bearer
 * token and checks the endpoint's ACL resource, to the module's request processor and the protocol behind it
 *
 * The callers are two admins whose roles grant every resource. Their tokens are real bearer tokens, issued by the
 * store's token issuer and read back by Magento's own token user context. Each test runs in a fresh application,
 * because the REST response Magento shares keeps every exception it was given.
 */
#[AppArea('webapi_rest')]
#[AppIsolation(true)]
#[DbIsolation(true)]
class McpEndpointTest extends TestCase
{
    private const ADMIN = 990201;
    private const OTHER_ADMIN = 990202;
    private const VERSION = '2025-06-18';
    private const COUNTRIES = 'directory.country_information_acquirer.get_countries_info';

    /**
     * @var McpEndpointClient
     */
    private McpEndpointClient $client;

    /**
     * @var string
     */
    private string $otherAdminToken;

    /**
     * @var int
     */
    private int $nextId = 1;

    protected function setUp(): void
    {
        $adminToken = $this->tokenFor(AdminRoles::grant(['Magento_Backend::all'], self::ADMIN));
        $this->otherAdminToken = $this->tokenFor(AdminRoles::grant(['Magento_Backend::all'], self::OTHER_ADMIN));

        $this->client = new McpEndpointClient();
        $this->client->authenticateWith($adminToken);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnAnonymousRequestIsRefusedByMagentoAndTheEndpointAnswersNothing(): void
    {
        $this->client->authenticateWith('');

        $response = $this->client->post($this->request('ping'));

        $this->assertRefusedByMagento($response);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnAdminWhoseRoleLacksTheEndpointResourceIsRefusedByMagento(): void
    {
        $this->client->authenticateWith($this->tokenFor(AdminRoles::grant(['Magento_Backend::stores'], 990203)));

        $response = $this->client->post($this->initializeRequest());

        $this->assertRefusedByMagento($response);
    }

    public function testADisabledServerRefusesEveryRequest(): void
    {
        $response = $this->client->post($this->request('ping'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(-32001, $this->errorOf($response)['code']);
        self::assertNull($this->json($response)['id']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testASessionIsOpenedAndUsedForEveryMethodAndMetaTool(): void
    {
        $opened = $this->client->post($this->request('initialize', [
            'protocolVersion' => self::VERSION,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'End to end', 'version' => '1.0'],
        ]));
        self::assertSame(200, $opened->getStatusCode());
        self::assertSame('application/json', $this->headerOf($opened, 'Content-Type'));
        self::assertSame(self::VERSION, $this->resultOf($opened)['protocolVersion']);
        $session = $this->headerOf($opened, 'Mcp-Session-Id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $session);

        $initialized = $this->client->post(
            $this->notification('notifications/initialized'),
            $this->sessionHeaders($session)
        );
        self::assertSame(202, $initialized->getStatusCode());
        self::assertSame('', $initialized->getBody());

        $ping = $this->client->post($this->request('ping', null, 7), $this->sessionHeaders($session));
        self::assertSame(200, $ping->getStatusCode());
        self::assertSame('{"jsonrpc":"2.0","id":7,"result":{}}', $ping->getBody());

        $tools = $this->client->post($this->request('tools/list'), $this->sessionHeaders($session));
        self::assertSame(
            ['discover_abilities', 'get_ability_info', 'execute_ability'],
            array_column($this->arrayAt($this->resultOf($tools), 'tools'), 'name')
        );

        $discovered = $this->callTool($session, 'discover_abilities', ['query' => 'country']);
        self::assertContains(
            self::COUNTRIES,
            array_column($this->arrayAt($this->structuredContentOf($discovered), 'abilities'), 'name')
        );

        $countries = $this->callTool($session, 'execute_ability', ['name' => self::COUNTRIES]);
        self::assertContains('US', $this->countryIdsOf($countries));

        $batch = $this->client->post(
            '[' . $this->request('ping', null, 21) . ',' . $this->request('tools/list', null, 22) . ']',
            $this->sessionHeaders($session)
        );
        self::assertSame(200, $batch->getStatusCode());
        $answers = $this->json($batch);
        self::assertTrue(array_is_list($answers));
        self::assertSame([21, 22], array_column($answers, 'id'));

        $emptyBatch = $this->client->post('[]', $this->sessionHeaders($session));
        self::assertSame(400, $emptyBatch->getStatusCode());
        self::assertSame(-32600, $this->errorOf($emptyBatch)['code']);
        self::assertNull($this->json($emptyBatch)['id']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testReadOnlyModeDeniesAWriteAbilityAndTheProductIsNotCreated(): void
    {
        $session = $this->initialize();
        $sku = 'mcp-end-to-end-read-only';

        $saved = $this->client->post($this->toolCall('execute_ability', [
            'name' => 'catalog.product_repository.save',
            'arguments' => ['product' => [
                'sku' => $sku,
                'name' => 'MCP end to end',
                'attribute_set_id' => 4,
                'price' => 1,
                'type_id' => 'simple',
            ]],
        ]), $this->sessionHeaders($session));

        self::assertSame(200, $saved->getStatusCode());
        $error = $this->errorOf($saved);
        self::assertSame(-32003, $error['code']);
        self::assertSame(
            ['ability' => 'catalog.product_repository.save', 'reason' => 'read_only_mode'],
            $error['data']
        );
        $lookup = $this->callTool($session, 'execute_ability', [
            'name' => 'catalog.product_repository.get',
            'arguments' => ['sku' => $sku],
        ]);
        self::assertTrue($this->resultOf($lookup)['isError'], 'The product does not exist.');
    }

    /**
     * The countries ability takes anonymous callers, which are not exposed either: the deny-list is the reason given
     */
    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testADenyListOverrideDeniesTheAbility(): void
    {
        $this->override(self::COUNTRIES, true, false);
        $session = $this->initialize();

        $error = $this->errorOf($this->callTool($session, 'execute_ability', ['name' => self::COUNTRIES]));

        self::assertSame(-32003, $error['code']);
        self::assertSame(['ability' => self::COUNTRIES, 'reason' => 'deny_list'], $error['data']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testAConfirmOverrideRunsTheAbilityOnlyWithATokenForThatExactCallUsedOnce(): void
    {
        $this->override(self::COUNTRIES, false, true);
        $session = $this->initialize();

        $unconfirmed = $this->callTool($session, 'execute_ability', ['name' => self::COUNTRIES]);
        self::assertSame(200, $unconfirmed->getStatusCode());
        $token = $this->confirmationTokenOf($unconfirmed);

        $confirmed = $this->callTool(
            $session,
            'execute_ability',
            ['name' => self::COUNTRIES, 'confirmationToken' => $token]
        );
        self::assertFalse($this->resultOf($confirmed)['isError']);
        self::assertContains('US', $this->countryIdsOf($confirmed));

        $replayed = $this->callTool(
            $session,
            'execute_ability',
            ['name' => self::COUNTRIES, 'confirmationToken' => $token]
        );
        $freshToken = $this->confirmationTokenOf($replayed);
        self::assertNotSame($token, $freshToken, 'A used token confirms nothing, and a new one is handed out.');

        $otherArguments = $this->callTool($session, 'execute_ability', [
            'name' => self::COUNTRIES,
            'arguments' => ['storeId' => 1],
            'confirmationToken' => $freshToken,
        ]);
        self::assertNotSame($freshToken, $this->confirmationTokenOf($otherArguments));

        $flag = $this->callTool($session, 'execute_ability', ['name' => self::COUNTRIES, 'confirm' => true]);
        $result = $this->resultOf($flag);
        self::assertTrue($result['isError']);
        $text = $this->arrayAt($this->arrayAt($result, 'content'), 0)['text'] ?? null;
        self::assertIsString($text);
        self::assertStringContainsString('confirmationToken', $text);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAMessageNeedsTheCallersOwnLiveSession(): void
    {
        $session = $this->initialize();

        $withoutSession = $this->client->post($this->request('ping'));
        self::assertSame(400, $withoutSession->getStatusCode(), $withoutSession->getBody());

        $unknownSession = $this->client->post(
            $this->request('ping', null, 5),
            $this->sessionHeaders(str_repeat('a', 32))
        );
        self::assertSame(404, $unknownSession->getStatusCode(), $unknownSession->getBody());
        $unknownBody = $unknownSession->getBody();

        $this->client->authenticateWith($this->otherAdminToken);
        $foreignSession = $this->client->post($this->request('ping', null, 5), $this->sessionHeaders($session));
        self::assertSame(404, $foreignSession->getStatusCode(), $foreignSession->getBody());
        self::assertSame($unknownBody, $foreignSession->getBody(), 'A foreign session reads as an unknown one.');
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnUnsupportedProtocolVersionIsABadRequest(): void
    {
        $session = $this->initialize();

        $response = $this->client->post(
            $this->request('ping'),
            ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '1999-01-01']
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32600, $this->errorOf($response)['code']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAForeignOriginIsForbiddenAndTheStoresOwnOriginIsServed(): void
    {
        $scopeConfig = Bootstrap::getObjectManager()->get(ScopeConfigInterface::class);
        self::assertInstanceOf(ScopeConfigInterface::class, $scopeConfig);
        $baseUrl = $scopeConfig->getValue('web/unsecure/base_url');
        self::assertIsString($baseUrl);
        $ownOrigin = rtrim($baseUrl, '/');

        $foreign = $this->client->post($this->initializeRequest(), ['Origin' => 'http://rebound.example']);
        self::assertSame(403, $foreign->getStatusCode(), $foreign->getBody());
        self::assertSame(-32600, $this->errorOf($foreign)['code']);
        self::assertFalse($foreign->getHeader('Mcp-Session-Id'), 'No session was opened.');

        $own = $this->client->post($this->initializeRequest(), ['Origin' => $ownOrigin]);
        self::assertSame(200, $own->getStatusCode(), $own->getBody());
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testGetIsNotAllowedAndDeleteEndsTheSession(): void
    {
        $session = $this->initialize();

        $get = $this->client->get($this->sessionHeaders($session));
        self::assertSame(405, $get->getStatusCode());
        self::assertSame('POST, DELETE', $this->headerOf($get, 'Allow'));
        self::assertSame('', $get->getBody());

        $deleted = $this->client->delete($this->sessionHeaders($session));
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('', $deleted->getBody());

        $ping = $this->client->post($this->request('ping'), $this->sessionHeaders($session));
        self::assertSame(404, $ping->getStatusCode());
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAStoreCodeOtherThanAllIsRefused(): void
    {
        $response = $this->client->post($this->initializeRequest(), [], '/rest/default/V1/mcp');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->errorOf($response);
        self::assertSame(-32600, $error['code']);
        self::assertSame('Use the endpoint with store code all: /rest/all/V1/mcp.', $error['message']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testASessionOutlivesACacheFlush(): void
    {
        $session = $this->initialize();
        $cacheManager = Bootstrap::getObjectManager()->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);

        $cacheManager->clean($cacheManager->getAvailableTypes());
        $ping = $this->client->post($this->request('ping'), $this->sessionHeaders($session));

        self::assertSame(200, $ping->getStatusCode());
        $answer = json_decode($ping->getBody(), false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $answer);
        self::assertEquals((object) [], $answer->result);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testTheBuiltInDenyListDeniesATokenIssuerWithReadOnlyModeOff(): void
    {
        $session = $this->initialize();
        $ability = 'integration.customer_token_service.create_customer_access_token';

        $error = $this->errorOf($this->callTool($session, 'execute_ability', [
            'name' => $ability,
            'arguments' => ['username' => 'nobody@example.com', 'password' => 'not-a-password'],
        ]));

        self::assertSame(-32003, $error['code']);
        self::assertSame(['ability' => $ability, 'reason' => 'deny_list'], $error['data']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnAbilityAnyoneMayCallIsDeniedAfterInstall(): void
    {
        $session = $this->initialize();

        $error = $this->errorOf($this->callTool($session, 'execute_ability', ['name' => self::COUNTRIES]));

        self::assertSame(-32003, $error['code']);
        self::assertSame(['ability' => self::COUNTRIES, 'reason' => 'anonymous_ability'], $error['data']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    #[ConfigFixture(Config::XML_PATH_EXPOSE_ANONYMOUS_ABILITIES, '1')]
    public function testAnAbilityAnyoneMayCallRunsOnceTheSettingExposesIt(): void
    {
        $session = $this->initialize();

        $countries = $this->callTool($session, 'execute_ability', ['name' => self::COUNTRIES]);

        self::assertContains('US', $this->countryIdsOf($countries));
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    #[ConfigFixture(Config::XML_PATH_READ_ONLY_MODE, '0')]
    public function testACustomerSaveCarryingAPasswordHashIsRefusedByTheArgumentGuard(): void
    {
        $session = $this->initialize();
        $ability = 'customer.customer_repository.save';

        $error = $this->errorOf($this->callTool($session, 'execute_ability', [
            'name' => $ability,
            'arguments' => [
                'customerId' => 1,
                'customer' => ['email' => 'mcp-guard@example.com', 'firstname' => 'Guard', 'lastname' => 'Test'],
                'passwordHash' => 'a hash chosen by the client',
            ],
        ]));

        self::assertSame(-32003, $error['code']);
        self::assertSame(['ability' => $ability, 'reason' => 'argument_guard'], $error['data']);
        self::assertIsString($error['message']);
        self::assertStringStartsWith('Passwords are never set through MCP', $error['message']);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnUnknownAbilityIsAToolErrorTheModelCanCorrect(): void
    {
        $session = $this->initialize();

        $response = $this->callTool($session, 'execute_ability', ['name' => 'catalog.nothing_here.get']);

        self::assertSame(200, $response->getStatusCode());
        $result = $this->resultOf($response);
        self::assertTrue($result['isError']);
        $text = $this->arrayAt($this->arrayAt($result, 'content'), 0)['text'] ?? null;
        self::assertIsString($text);
        self::assertStringContainsString('catalog.nothing_here.get', $text);
    }

    /**
     * Magento refused the request with 401 before the endpoint answered: the endpoint wrote nothing
     *
     * @param RestResponse $response
     * @return void
     */
    private function assertRefusedByMagento(RestResponse $response): void
    {
        $refusal = $response->getException()[0] ?? null;
        self::assertInstanceOf(WebapiException::class, $refusal, $response->getBody());
        self::assertSame(401, $refusal->getHttpCode());
        self::assertSame('', $response->getBody());
        self::assertFalse($response->getHeader('Content-Type'));
        self::assertFalse($response->getHeader('Mcp-Session-Id'));
    }

    /**
     * Open a session as the current caller and announce it initialized
     *
     * @return string The session id
     */
    private function initialize(): string
    {
        $opened = $this->client->post($this->initializeRequest());
        self::assertSame(200, $opened->getStatusCode(), $opened->getBody());
        $session = $this->headerOf($opened, 'Mcp-Session-Id');
        $initialized = $this->client->post(
            $this->notification('notifications/initialized'),
            $this->sessionHeaders($session)
        );
        self::assertSame(202, $initialized->getStatusCode());

        return $session;
    }

    /**
     * @return string
     */
    private function initializeRequest(): string
    {
        return $this->request('initialize', [
            'protocolVersion' => self::VERSION,
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'End to end', 'version' => '1.0'],
        ]);
    }

    /**
     * Call a meta-tool in the session
     *
     * @param string $session
     * @param string $tool
     * @param array<string, mixed> $arguments
     * @return RestResponse
     */
    private function callTool(string $session, string $tool, array $arguments): RestResponse
    {
        return $this->client->post($this->toolCall($tool, $arguments), $this->sessionHeaders($session));
    }

    /**
     * @param string $tool
     * @param array<string, mixed> $arguments
     * @return string
     */
    private function toolCall(string $tool, array $arguments): string
    {
        return $this->request('tools/call', ['name' => $tool, 'arguments' => $arguments]);
    }

    /**
     * A JSON-RPC request
     *
     * @param string $method
     * @param array<string, mixed>|null $params
     * @param int|null $id
     * @return string
     */
    private function request(string $method, ?array $params = null, ?int $id = null): string
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id ?? $this->nextId++, 'method' => $method];
        if ($params !== null) {
            $message['params'] = $params;
        }

        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * A JSON-RPC notification
     *
     * @param string $method
     * @return string
     */
    private function notification(string $method): string
    {
        return json_encode(['jsonrpc' => '2.0', 'method' => $method], JSON_THROW_ON_ERROR);
    }

    /**
     * @param string $session
     * @return array<string, string>
     */
    private function sessionHeaders(string $session): array
    {
        return ['Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => self::VERSION];
    }

    /**
     * Store an exposure override for the ability
     *
     * @param string $ability
     * @param bool $deny
     * @param bool $confirm
     * @return void
     */
    private function override(string $ability, bool $deny, bool $confirm): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $override = $objectManager->create(AbilityOverrideInterface::class);
        self::assertInstanceOf(AbilityOverrideInterface::class, $override);
        $repository = $objectManager->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepositoryInterface::class, $repository);

        $repository->save($override->setAbility($ability)->setDenied($deny)->setNeedsConfirmation($confirm));
    }

    /**
     * A bearer token for the caller, issued the way Magento issues admin tokens
     *
     * @param UserContextInterface $caller
     * @return string
     */
    private function tokenFor(UserContextInterface $caller): string
    {
        $objectManager = Bootstrap::getObjectManager();
        $issuer = $objectManager->get(UserTokenIssuerInterface::class);
        self::assertInstanceOf(UserTokenIssuerInterface::class, $issuer);
        $parameters = $objectManager->get(UserTokenParametersInterfaceFactory::class);
        self::assertInstanceOf(UserTokenParametersInterfaceFactory::class, $parameters);

        return $issuer->create($caller, $parameters->create());
    }

    /**
     * @param RestResponse $response
     * @param string $name
     * @return string
     */
    private function headerOf(RestResponse $response, string $name): string
    {
        $header = $response->getHeader($name);
        self::assertInstanceOf(HeaderInterface::class, $header, sprintf('The response has no %s header.', $name));

        return $header->getFieldValue();
    }

    /**
     * @param RestResponse $response
     * @return array<mixed>
     */
    private function json(RestResponse $response): array
    {
        $body = $response->getBody();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $body);

        return $decoded;
    }

    /**
     * @param RestResponse $response
     * @return array<mixed>
     */
    private function resultOf(RestResponse $response): array
    {
        return $this->arrayAt($this->json($response), 'result');
    }

    /**
     * @param RestResponse $response
     * @return array<mixed>
     */
    private function errorOf(RestResponse $response): array
    {
        return $this->arrayAt($this->json($response), 'error');
    }

    /**
     * @param RestResponse $response
     * @return array<mixed>
     */
    private function structuredContentOf(RestResponse $response): array
    {
        $result = $this->resultOf($response);
        self::assertFalse($result['isError'] ?? null, $response->getBody());

        return $this->arrayAt($result, 'structuredContent');
    }

    /**
     * The confirmation token of a -32004 answer, which names the countries ability
     *
     * @param RestResponse $response
     * @return string
     */
    private function confirmationTokenOf(RestResponse $response): string
    {
        $error = $this->errorOf($response);
        self::assertSame(-32004, $error['code'], $response->getBody());
        $data = $this->arrayAt($error, 'data');
        self::assertSame(self::COUNTRIES, $data['ability']);
        $token = $data['confirmationToken'] ?? null;
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        return $token;
    }

    /**
     * The ids of the countries a successful call of the countries ability returned
     *
     * @param RestResponse $response
     * @return list<mixed>
     */
    private function countryIdsOf(RestResponse $response): array
    {
        return array_column($this->arrayAt($this->structuredContentOf($response), 'result'), 'id');
    }

    /**
     * @param array<mixed> $array
     * @param int|string $key
     * @return array<mixed>
     */
    private function arrayAt(array $array, int|string $key): array
    {
        $value = $array[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }
}
