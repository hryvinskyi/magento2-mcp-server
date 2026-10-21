<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Http;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Http\McpRequestProcessor;
use Hryvinskyi\McpServer\Model\Http\OriginPolicy;
use Hryvinskyi\McpServer\Model\Http\Verb\EndSession;
use Hryvinskyi\McpServer\Model\Http\Verb\PostMessages;
use Hryvinskyi\McpServer\Model\Http\Verb\VerbInterface;
use Hryvinskyi\McpServer\Model\Protocol\Answers;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Hryvinskyi\McpServer\Model\Protocol\TransportContextFactory;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use Laminas\Http\Header\HeaderInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\ErrorProcessor;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Framework\Webapi\Rest\Response\RendererFactory;
use Magento\Framework\Webapi\Rest\Response\RendererInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Webapi\Controller\Rest\RequestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The MCP endpoint's request processor between a REST request and the protocol: what it takes, what it refuses
 * before the protocol runs, what it hands over, and how it writes the answer
 */
class McpRequestProcessorTest extends TestCase
{
    private const SERVED_ORIGIN = 'https://shop.example';

    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    /**
     * @var Response
     */
    private Response $response;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
        $this->response = new Response(
            $this->rendererFactory(),
            $this->createStub(ErrorProcessor::class),
            $this->createStub(State::class)
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        return [
            'the endpoint' => ['/V1/mcp', true],
            'the endpoint with a trailing slash' => ['/V1/mcp/', true],
            'a route under the endpoint' => ['/V1/mcp/servers', false],
            'a route that starts like the endpoint' => ['/V1/mcpx', false],
            'another route' => ['/V1/products', false],
        ];
    }

    #[DataProvider('paths')]
    public function testOnlyTheEndpointsExactPathIsTaken(string $path, bool $taken): void
    {
        self::assertSame($taken, $this->processor()->canProcess($this->request('POST', path: $path)));
    }

    public function testMagentosRequestValidatorRunsFirstAndItsRefusalIsLeftToMagento(): void
    {
        $refusal = new AuthorizationException(__('The consumer isn\'t authorized to access %1.', 'the endpoint'));
        $validator = $this->createMock(RequestValidator::class);
        $validator->expects(self::once())->method('validate')->willThrowException($refusal);

        try {
            $this->processor(enabled: false, validator: $validator)
                ->process($this->request('POST', $this->initialize()));
            self::fail('The refusal reaches Magento.');
        } catch (AuthorizationException $caught) {
            self::assertSame($refusal, $caught);
        }

        self::assertSame(200, $this->response->getStatusCode());
        self::assertSame('', $this->response->getBody());
        self::assertSame([], array_values($this->kit->sessions->sessions), 'The server never ran.');
    }

    public function testADisabledServerRefusesWithForbiddenAndServerDisabled(): void
    {
        $this->processor(enabled: false)->process($this->request('POST', '{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        self::assertSame(403, $this->response->getStatusCode());
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32001,"message":"The MCP server is disabled."}}',
            $this->response->getBody()
        );
        self::assertSame('application/json', $this->header('Content-Type'));
    }

    public function testAMethodWithoutAVerbIsNotAllowedAndTheAllowHeaderListsTheVerbs(): void
    {
        $this->processor()->process($this->request('GET'));

        self::assertSame(405, $this->response->getStatusCode());
        self::assertSame('POST, DELETE', $this->header('Allow'));
        self::assertSame('', $this->response->getBody());
    }

    public function testAStoreOtherThanTheAdminStoreIsABadRequest(): void
    {
        $this->processor(storeCode: 'default')->process($this->request('POST', $this->initialize()));

        self::assertSame(400, $this->response->getStatusCode());
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":'
            . '"Use the endpoint with store code all: /rest/all/V1/mcp."}}',
            $this->response->getBody()
        );
        self::assertSame([], array_values($this->kit->sessions->sessions), 'The server never ran.');
    }

    public function testAnOriginTheStoreDoesNotServeIsForbidden(): void
    {
        $this->processor()->process(
            $this->request('POST', $this->initialize(), ['Origin' => 'http://rebound.example'])
        );

        self::assertSame(403, $this->response->getStatusCode());
        self::assertStringContainsString('"code":-32600', (string) $this->response->getBody());
        self::assertSame([], array_values($this->kit->sessions->sessions), 'The server never ran.');
    }

    public function testInitializeFromTheStoresOwnOriginAnnouncesTheSessionItOpened(): void
    {
        $this->processor()->process($this->request('POST', $this->initialize(), ['Origin' => self::SERVED_ORIGIN]));

        self::assertSame(200, $this->response->getStatusCode());
        self::assertSame('application/json', $this->header('Content-Type'));
        $sessions = array_values($this->kit->sessions->sessions);
        self::assertCount(1, $sessions);
        self::assertSame($sessions[0]->getId(), $this->header('Mcp-Session-Id'));
        self::assertTrue($sessions[0]->belongsTo(new Caller(UserContextInterface::USER_TYPE_ADMIN, 7)));
    }

    public function testTheCallerAndTheRawSessionHeadersAreHandedToTheVerb(): void
    {
        $verb = $this->recordingVerb();

        $this->processor(verbs: ['POST' => $verb])->process($this->request('POST', '{}', [
            'Mcp-Session-Id' => ' not-an-id ',
            'MCP-Protocol-Version' => '2025-06-18',
        ]));

        $context = $verb->context;
        self::assertInstanceOf(TransportContext::class, $context);
        self::assertSame(UserContextInterface::USER_TYPE_ADMIN, $context->getCaller()->getUserType());
        self::assertSame(7, $context->getCaller()->getUserId());
        self::assertSame('http', $context->getTransport());
        self::assertSame(' not-an-id ', $context->getSessionId());
        self::assertSame('2025-06-18', $context->getProtocolVersion());
    }

    public function testAnAcceptedAnswerIsSentWithoutBodyOrContentType(): void
    {
        $session = $this->kit->openSession();

        $this->processor(caller: $this->kit->admin())->process($this->request(
            'POST',
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            ['Mcp-Session-Id' => $session->getId()]
        ));

        self::assertSame(202, $this->response->getStatusCode());
        self::assertSame('', $this->response->getBody());
        self::assertFalse($this->response->getHeader('Content-Type'));
    }

    public function testDeleteEndsTheSessionWithNoContent(): void
    {
        $session = $this->kit->openSession();

        $this->processor(caller: $this->kit->admin())->process($this->request(
            'DELETE',
            '',
            ['Mcp-Session-Id' => $session->getId()]
        ));

        self::assertSame(204, $this->response->getStatusCode());
        self::assertSame('', $this->response->getBody());
        self::assertNull($this->kit->sessions->find($session->getId()));
    }

    public function testAFailureIsLoggedAndAnsweredAsAJsonRpcInternalError(): void
    {
        $failing = $this->createStub(VerbInterface::class);
        $failing->method('answer')->willThrowException(new \Error('The verb broke.'));

        $this->processor(verbs: ['POST' => $failing])->process($this->request('POST', '{}'));

        self::assertSame(200, $this->response->getStatusCode());
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}',
            $this->response->getBody()
        );
        self::assertSame(['The MCP endpoint failed: The verb broke.'], $this->kit->logger->messages('error'));
    }

    /**
     * The processor over the kit's protocol, as di.xml wires it, for the admin with id 7 unless a test says otherwise
     *
     * @param bool $enabled
     * @param string $storeCode
     * @param array<string, VerbInterface>|null $verbs
     * @param CallerInterface|null $caller
     * @param RequestValidator|null $validator Magento's request validator, letting every request through when null
     * @return McpRequestProcessor
     */
    private function processor(
        bool $enabled = true,
        string $storeCode = 'admin',
        ?array $verbs = null,
        ?CallerInterface $caller = null,
        ?RequestValidator $validator = null
    ): McpRequestProcessor {
        $server = $this->kit->server();
        $objectManager = new ConstructingObjectManager([
            CallerInterface::class => Caller::class,
            TransportContext::class => TransportContext::class,
        ]);

        return new McpRequestProcessor(
            $validator ?? $this->createStub(RequestValidator::class),
            new FakeConfig($enabled),
            $this->storeManager($storeCode),
            $this->originPolicy(),
            $this->userContext($caller ?? new Caller(UserContextInterface::USER_TYPE_ADMIN, 7)),
            new CallerInterfaceFactory($objectManager),
            new TransportContextFactory($objectManager),
            $this->kit->answers(),
            $this->response,
            $this->kit->logger,
            $verbs ?? ['POST' => new PostMessages($server), 'DELETE' => new EndSession($server)]
        );
    }

    /**
     * A REST request for the endpoint, unless another path is given
     *
     * @param string $method
     * @param string $body
     * @param array<string, string> $headers
     * @param string $path
     * @return Request
     */
    private function request(string $method, string $body = '', array $headers = [], string $path = '/V1/mcp'): Request
    {
        $request = $this->createStub(Request::class);
        $request->method('getPathInfo')->willReturn($path);
        $request->method('getMethod')->willReturn($method);
        $request->method('getContent')->willReturn($body);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string|false => $headers[$name] ?? false
        );

        return $request;
    }

    /**
     * @return string
     */
    private function initialize(): string
    {
        return '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}';
    }

    /**
     * A verb that remembers the context it was handed and accepts
     *
     * @return VerbInterface&object{context: ?TransportContext}
     */
    private function recordingVerb(): VerbInterface
    {
        $answers = $this->kit->answers();

        return new class ($answers) implements VerbInterface {
            /**
             * @var TransportContext|null
             */
            public ?TransportContext $context = null;

            /**
             * @param Answers $answers
             */
            public function __construct(private readonly Answers $answers)
            {
            }

            /**
             * @inheritDoc
             */
            public function answer(Request $request, TransportContext $context): ProtocolAnswer
            {
                $this->context = $context;

                return $this->answers->accepted();
            }
        };
    }

    /**
     * @param string $name
     * @return string
     */
    private function header(string $name): string
    {
        $header = $this->response->getHeader($name);
        self::assertInstanceOf(HeaderInterface::class, $header, sprintf('The response has no %s header.', $name));

        return $header->getFieldValue();
    }

    /**
     * @param string $storeCode The code of the current store
     * @return StoreManagerInterface
     */
    private function storeManager(string $storeCode): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn($storeCode);
        $store->method('getId')->willReturn(0);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getStores')->willReturn([$store]);

        return $storeManager;
    }

    /**
     * An origin policy for a store served at SERVED_ORIGIN
     *
     * @return OriginPolicy
     */
    private function originPolicy(): OriginPolicy
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(self::SERVED_ORIGIN . '/');
        $storeManager = $this->storeManager('admin');

        return new OriginPolicy($storeManager, $scopeConfig);
    }

    /**
     * @param CallerInterface $caller
     * @return UserContextInterface
     */
    private function userContext(CallerInterface $caller): UserContextInterface
    {
        $userContext = $this->createStub(UserContextInterface::class);
        $userContext->method('getUserType')->willReturn($caller->getUserType());
        $userContext->method('getUserId')->willReturn($caller->getUserId());

        return $userContext;
    }

    /**
     * A renderer factory whose renderer is never asked for: the processor writes raw bodies
     *
     * @return RendererFactory
     */
    private function rendererFactory(): RendererFactory
    {
        $factory = $this->createStub(RendererFactory::class);
        $factory->method('get')->willReturn($this->createStub(RendererInterface::class));

        return $factory;
    }
}
