<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Data\Invocation;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use Hryvinskyi\McpServer\Model\Protocol\Answers;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\CallContextFactory;
use Hryvinskyi\McpServer\Model\Protocol\Dispatcher;
use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use Hryvinskyi\McpServer\Model\Protocol\JsonObjects;
use Hryvinskyi\McpServer\Model\Protocol\JsonRpcResponses;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\MessageFactory;
use Hryvinskyi\McpServer\Model\Protocol\MessageParser;
use Hryvinskyi\McpServer\Model\Protocol\Method\Initialize;
use Hryvinskyi\McpServer\Model\Protocol\Method\Initialized;
use Hryvinskyi\McpServer\Model\Protocol\Method\MethodHandlerInterface;
use Hryvinskyi\McpServer\Model\Protocol\Method\Ping;
use Hryvinskyi\McpServer\Model\Protocol\Method\ToolsCall;
use Hryvinskyi\McpServer\Model\Protocol\Method\ToolsList;
use Hryvinskyi\McpServer\Model\Protocol\ParsedBody;
use Hryvinskyi\McpServer\Model\Protocol\ParsedBodyFactory;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswerFactory;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolVersions;
use Hryvinskyi\McpServer\Model\Protocol\Reply;
use Hryvinskyi\McpServer\Model\Protocol\ReplyFactory;
use Hryvinskyi\McpServer\Model\Protocol\Server;
use Hryvinskyi\McpServer\Model\Protocol\SessionGate;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ArgumentReader;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ExecuteAbility;
use Hryvinskyi\McpServer\Model\Protocol\Tool\Outcome\ConfirmationAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Tool\Outcome\DeniedAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Tool\Outcome\FailedAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Tool\Outcome\SucceededAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolInterface;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolRegistry;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolResults;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Hryvinskyi\McpServer\Model\Session\Session;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Module\PackageInfo;
use PHPUnit\Framework\TestCase;

/**
 * The real protocol classes wired the way di.xml wires them, over an in-memory session store, for unit tests
 *
 * Generated factories are the real ones, over an object manager that builds the preferred classes.
 */
class ProtocolKit
{
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * @var InMemorySessionStore
     */
    public InMemorySessionStore $sessions;

    /**
     * @var RecordingLogger
     */
    public RecordingLogger $logger;

    /**
     * @var InMemoryConfirmationStore
     */
    public InMemoryConfirmationStore $confirmations;

    /**
     * @var ConstructingObjectManager
     */
    private ConstructingObjectManager $objectManager;

    /**
     * @param TestCase $test The test that builds the package info double
     */
    public function __construct(
        private readonly TestCase $test
    ) {
        $this->sessions = new InMemorySessionStore();
        $this->logger = new RecordingLogger();
        $this->confirmations = new InMemoryConfirmationStore();
        $this->objectManager = new ConstructingObjectManager([
            Reply::class => Reply::class,
            Message::class => Message::class,
            ParsedBody::class => ParsedBody::class,
            ProtocolAnswer::class => ProtocolAnswer::class,
            CallContext::class => CallContext::class,
            ClientInterface::class => Client::class,
            InvocationInterface::class => Invocation::class,
            InvocationResultInterface::class => InvocationResult::class,
            AbilityPageInterface::class => AbilityPage::class,
        ]);
    }

    /**
     * The server, with the module's handlers plus any the test adds or replaces
     *
     * @param list<ToolInterface>|null $tools The tools, or one fake tool
     * @param array<string,MethodHandlerInterface> $handlers
     * @param int $maxBodyBytes
     * @param int $maxBatchSize
     * @return Server
     */
    public function server(
        ?array $tools = null,
        array $handlers = [],
        int $maxBodyBytes = 1048576,
        int $maxBatchSize = 20
    ): Server {
        return new Server(
            $this->parser($maxBatchSize),
            new SessionGate($this->sessions, $this->versions()),
            $this->sessions,
            $this->dispatcher($tools ?? [new FakeTool()], $handlers),
            $this->callContextFactory(),
            $this->replyFactory(),
            new JsonRpcResponses(),
            $this->answers(),
            $this->logger,
            $maxBodyBytes
        );
    }

    /**
     * The dispatcher, with the module's handlers plus any the test adds or replaces
     *
     * @param list<ToolInterface> $tools
     * @param array<string,MethodHandlerInterface> $handlers
     * @return Dispatcher
     */
    public function dispatcher(array $tools = [], array $handlers = []): Dispatcher
    {
        $packageInfo = $this->test->getMockBuilder(PackageInfo::class)->disableOriginalConstructor()->getMock();
        $packageInfo->method('getVersion')->willReturn('1.0.0');
        $registry = new ToolRegistry($tools);

        return new Dispatcher(
            $this->replyFactory(),
            $this->logger,
            array_merge([
                'initialize' => new Initialize(
                    $this->sessions,
                    $this->versions(),
                    $this->clientFactory(),
                    $packageInfo,
                    $this->replyFactory()
                ),
                'notifications/initialized' => new Initialized($this->replyFactory()),
                'ping' => new Ping($this->replyFactory()),
                'tools/list' => new ToolsList($this->replyFactory(), $registry),
                'tools/call' => new ToolsCall(
                    $this->replyFactory(),
                    $this->toolResults(),
                    new JsonObjects(),
                    $registry
                ),
            ], $handlers)
        );
    }

    /**
     * @param int $maxBatchSize
     * @return MessageParser
     */
    public function parser(int $maxBatchSize = 20): MessageParser
    {
        return new MessageParser(
            new MessageFactory($this->objectManager),
            new ParsedBodyFactory($this->objectManager),
            new JsonObjects(),
            $maxBatchSize
        );
    }

    /**
     * @return ProtocolVersions
     */
    public function versions(): ProtocolVersions
    {
        return new ProtocolVersions(self::VERSIONS);
    }

    /**
     * @return Answers
     */
    public function answers(): Answers
    {
        return new Answers(new ProtocolAnswerFactory($this->objectManager), new JsonRpcResponses(), new JsonEncoder());
    }

    /**
     * @return ToolResults
     */
    public function toolResults(): ToolResults
    {
        return new ToolResults(new JsonEncoder());
    }

    /**
     * @return ArgumentReader
     */
    public function argumentReader(): ArgumentReader
    {
        return new ArgumentReader(new JsonObjects());
    }

    /**
     * `execute_ability` over this invoker and the kit's confirmation store, with every outcome answered as di.xml
     * wires it
     *
     * @param FakeInvoker $invoker
     * @return ExecuteAbility
     */
    public function executeAbility(FakeInvoker $invoker): ExecuteAbility
    {
        $toolResults = $this->toolResults();

        return new ExecuteAbility(
            $invoker,
            $this->invocationFactory(),
            $this->clientFactory(),
            $this->argumentReader(),
            $toolResults,
            $this->confirmations,
            [
                'succeeded' => new SucceededAnswer($toolResults),
                'failed' => new FailedAnswer($toolResults),
                'denied' => new DeniedAnswer(),
                'needs_confirmation' => new ConfirmationAnswer($this->confirmations),
            ]
        );
    }

    /**
     * @return ReplyFactory
     */
    public function replyFactory(): ReplyFactory
    {
        return new ReplyFactory($this->objectManager);
    }

    /**
     * @return CallContextFactory
     */
    public function callContextFactory(): CallContextFactory
    {
        return new CallContextFactory($this->objectManager);
    }

    /**
     * @return ClientInterfaceFactory
     */
    public function clientFactory(): ClientInterfaceFactory
    {
        return new ClientInterfaceFactory($this->objectManager);
    }

    /**
     * @return InvocationInterfaceFactory
     */
    public function invocationFactory(): InvocationInterfaceFactory
    {
        return new InvocationInterfaceFactory($this->objectManager);
    }

    /**
     * An admin caller
     *
     * @param int $userId
     * @return CallerInterface
     */
    public function admin(int $userId = 1): CallerInterface
    {
        return new Caller(UserContextInterface::USER_TYPE_ADMIN, $userId);
    }

    /**
     * What the HTTP transport knows of a request by this caller
     *
     * @param string $sessionId
     * @param string $protocolVersion
     * @param CallerInterface|null $caller The admin with id 1 when null
     * @return TransportContext
     */
    public function context(
        string $sessionId = '',
        string $protocolVersion = '',
        ?CallerInterface $caller = null
    ): TransportContext {
        return new TransportContext($caller ?? $this->admin(), 'http', $sessionId, $protocolVersion);
    }

    /**
     * A session of the admin with id 1, already open
     *
     * @param string $protocolVersion
     * @return Session
     */
    public function openSession(string $protocolVersion = '2025-06-18'): Session
    {
        return $this->sessions->add(
            str_repeat('ab', 16),
            $this->admin(),
            new Client('Test Client', '2.0'),
            $protocolVersion
        );
    }

    /**
     * The call context of a request in the session
     *
     * @param Session|null $session
     * @return CallContext
     */
    public function callContext(?Session $session = null): CallContext
    {
        return new CallContext($this->context($session?->getId() ?? ''), $session);
    }

    /**
     * A catalog over one fake source holding a product reader, a product writer and a country reader
     *
     * @param bool $allowed What the source answers for the caller's ACL
     * @return AbilityCatalog
     */
    public function catalog(bool $allowed = true): AbilityCatalog
    {
        return new AbilityCatalog(
            new AbilityPageInterfaceFactory($this->objectManager),
            $this->logger,
            ['webapi' => new FakeAbilitySource('webapi', [
                new Ability(
                    'catalog.product_repository.get_list',
                    'Product list',
                    'Magento_Catalog',
                    'webapi',
                    'GET /V1/products',
                    AbilityAccess::Read,
                    ['Magento_Catalog::products'],
                    'Lists products matching the search criteria.'
                ),
                new Ability(
                    'catalog.product_repository.save',
                    'Save a product',
                    'Magento_Catalog',
                    'webapi',
                    'POST /V1/products',
                    AbilityAccess::Write,
                    ['Magento_Catalog::products', 'Magento_Catalog::edit']
                ),
                new Ability(
                    'directory.country_information_acquirer.get_countries_info',
                    'Countries',
                    'Magento_Directory',
                    'webapi',
                    'GET /V1/directory/countries',
                    AbilityAccess::Read,
                    ['anonymous']
                ),
            ], $allowed)]
        );
    }
}
