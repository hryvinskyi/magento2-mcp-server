<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol;

use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\Method\MethodHandlerInterface;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Reply;
use Hryvinskyi\McpServer\Model\Protocol\Server;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeInvoker;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeTool;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The protocol as a client meets it: opening a session, the session and version rules, the body and batch limits,
 * notifications, batches and ending a session
 */
class ServerTest extends TestCase
{
    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    /**
     * @var Server
     */
    private Server $server;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
        $this->server = $this->kit->server();
    }

    public function testInitializeOpensASessionForTheCallerAndItsClient(): void
    {
        $answer = $this->server->handle(
            $this->request('initialize', 1, [
                'protocolVersion' => '2025-03-26',
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'Example Client', 'version' => '2.1.0'],
            ]),
            $this->kit->context('', '', $this->kit->admin(7))
        );

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        $session = $this->kit->sessions->find($answer->getSessionId());
        self::assertNotNull($session);
        self::assertSame(7, $session->getCaller()->getUserId());
        self::assertSame(UserContextInterface::USER_TYPE_ADMIN, $session->getCaller()->getUserType());
        self::assertSame('Example Client', $session->getClient()->getName());
        self::assertSame('2.1.0', $session->getClient()->getVersion());
        self::assertSame('2025-03-26', $session->getProtocolVersion());

        $body = $this->decode($answer);
        self::assertSame(1, $body['id']);
        $result = $this->arrayAt($body, 'result');
        self::assertSame('2025-03-26', $result['protocolVersion']);
        self::assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        self::assertSame(['name' => 'Magento MCP Server', 'version' => '1.0.0'], $result['serverInfo']);
        self::assertIsString($result['instructions']);
        self::assertStringContainsString('discover_abilities', $result['instructions']);
        self::assertStringContainsString('get_ability_info', $result['instructions']);
        self::assertStringContainsString('execute_ability', $result['instructions']);
    }

    public function testInitializeAnswersTheNewestVersionWhenTheRequestedOneIsUnsupported(): void
    {
        $answer = $this->server->handle(
            $this->request('initialize', 1, ['protocolVersion' => '2099-01-01']),
            $this->kit->context()
        );

        self::assertSame('2025-11-25', $this->arrayAt($this->decode($answer), 'result')['protocolVersion']);
        self::assertSame('2025-11-25', $this->kit->sessions->find($answer->getSessionId())?->getProtocolVersion());
    }

    public function testInitializeCutsAnOverlongClientNameAndIgnoresClientInfoThatIsNotAString(): void
    {
        $answer = $this->server->handle(
            $this->request('initialize', 1, [
                'protocolVersion' => '2025-06-18',
                'clientInfo' => ['name' => str_repeat('n', 300), 'version' => ['not', 'a', 'string']],
            ]),
            $this->kit->context()
        );

        $client = $this->kit->sessions->find($answer->getSessionId())?->getClient();
        self::assertNotNull($client);
        self::assertSame(str_repeat('n', 255), $client->getName());
        self::assertSame('', $client->getVersion());
    }

    public function testInitializeIgnoresASessionHeaderAndOpensANewSession(): void
    {
        $existing = $this->kit->openSession();

        $answer = $this->server->handle(
            $this->request('initialize', 1, ['protocolVersion' => '2025-06-18']),
            $this->kit->context($existing->getId(), '1999-01-01')
        );

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertNotSame($existing->getId(), $answer->getSessionId());
    }

    public function testOnlyTheAnswerToInitializeAnnouncesASession(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle($this->request('ping', 2), $this->kit->context($session->getId()));

        self::assertSame('', $answer->getSessionId());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function messagesThatNeedASession(): array
    {
        return [
            'request' => ['{"jsonrpc":"2.0","id":1,"method":"ping"}'],
            'notification' => ['{"jsonrpc":"2.0","method":"notifications/initialized"}'],
            'client response' => ['{"jsonrpc":"2.0","id":5,"result":{}}'],
            'batch' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]'],
        ];
    }

    #[DataProvider('messagesThatNeedASession')]
    public function testAMessageWithoutASessionIdIsABadRequest(string $body): void
    {
        $answer = $this->server->handle($body, $this->kit->context());

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, 'Mcp-Session-Id');
    }

    #[DataProvider('messagesThatNeedASession')]
    public function testAMessageWithAnUnknownSessionIsNotFound(string $body): void
    {
        $this->kit->openSession();

        $answer = $this->server->handle($body, $this->kit->context(str_repeat('0', 32)));

        self::assertSame(AnswerStatus::NotFound, $answer->getStatus());
        $this->assertError($answer, null, -32600, 'Session not found');
    }

    public function testAMalformedSessionIdIsNotFound(): void
    {
        $answer = $this->server->handle($this->request('ping', 1), $this->kit->context('not-a-session'));

        self::assertSame(AnswerStatus::NotFound, $answer->getStatus());
    }

    #[DataProvider('messagesThatNeedASession')]
    public function testAnotherCallersSessionIsNotFoundExactlyLikeAnUnknownOne(string $body): void
    {
        $session = $this->kit->openSession();
        $unknown = $this->server->handle($body, $this->kit->context(str_repeat('0', 32)));

        $foreign = $this->server->handle($body, $this->kit->context($session->getId(), '', $this->kit->admin(2)));
        $integration = $this->server->handle(
            $body,
            $this->kit->context($session->getId(), '', new Caller(UserContextInterface::USER_TYPE_INTEGRATION, 1))
        );

        self::assertSame(AnswerStatus::NotFound, $foreign->getStatus());
        self::assertSame($unknown->getBody(), $foreign->getBody());
        self::assertSame(AnswerStatus::NotFound, $integration->getStatus());
        self::assertSame([], $this->kit->sessions->touched);
    }

    public function testAnUnsupportedProtocolVersionHeaderIsABadRequest(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(
            $this->request('ping', 1),
            $this->kit->context($session->getId(), '2099-01-01')
        );

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, '2099-01-01');
    }

    public function testAProtocolVersionHeaderOtherThanTheSessionsIsABadRequest(): void
    {
        $session = $this->kit->openSession('2025-06-18');

        $answer = $this->server->handle(
            $this->request('ping', 1),
            $this->kit->context($session->getId(), '2025-03-26')
        );

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, '2025-06-18');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptableVersionHeaders(): array
    {
        return ['no header' => [''], 'the session\'s version' => ['2025-06-18']];
    }

    #[DataProvider('acceptableVersionHeaders')]
    public function testARequestWithoutAVersionHeaderOrWithTheSessionsVersionIsServed(string $header): void
    {
        $session = $this->kit->openSession('2025-06-18');

        $answer = $this->server->handle($this->request('ping', 1), $this->kit->context($session->getId(), $header));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
    }

    public function testASessionInUseIsRecordedAsSeen(): void
    {
        $session = $this->kit->openSession();

        $this->server->handle($this->request('ping', 1), $this->kit->context($session->getId()));

        self::assertSame([$session->getId()], $this->kit->sessions->touched);
    }

    public function testPingAnswersAnEmptyJsonObject(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle($this->request('ping', 'p-1'), $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertSame('{"jsonrpc":"2.0","id":"p-1","result":{}}', $answer->getBody());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unansweredMessages(): array
    {
        return [
            'initialized notification' => ['{"jsonrpc":"2.0","method":"notifications/initialized"}'],
            'notification no handler knows' => [
                '{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"x":1}}',
            ],
            'client result' => ['{"jsonrpc":"2.0","id":3,"result":{"roots":[]}}'],
            'client error' => ['{"jsonrpc":"2.0","id":3,"error":{"code":-1,"message":"no"}}'],
        ];
    }

    #[DataProvider('unansweredMessages')]
    public function testNotificationsAndClientResponsesAreAcceptedWithoutABody(string $body): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle($body, $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Accepted, $answer->getStatus());
        self::assertSame('', $answer->getBody());
    }

    public function testANotificationWhoseHandlerFailsIsStillAcceptedAndTheFailureLogged(): void
    {
        $server = $this->kit->server(null, ['notifications/initialized' => $this->failingHandler(true)]);
        $session = $this->kit->openSession();

        $answer = $server->handle(
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Accepted, $answer->getStatus());
        self::assertCount(1, $this->kit->logger->messages('error'));
    }

    public function testARequestForAnUnknownMethodIsMethodNotFoundWithItsId(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle($this->request('resources/list', 9), $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        $this->assertError($answer, 9, -32601, 'resources/list');
    }

    public function testAHandlerThatThrowsAnswersInternalErrorAndIsLogged(): void
    {
        $server = $this->kit->server(null, ['ping' => $this->failingHandler()]);
        $session = $this->kit->openSession();

        $answer = $server->handle($this->request('ping', 4), $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        $this->assertError($answer, 4, -32603, 'Internal error');
        self::assertStringNotContainsString('database password', $answer->getBody());
        self::assertSame(['MCP method "ping" failed: database password leaked'], $this->kit->logger->messages('error'));
    }

    public function testAnInvalidBodyIsABadRequestWithANullId(): void
    {
        $answer = $this->server->handle('{"jsonrpc":', $this->kit->context());

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32700, 'Parse error');
    }

    public function testABatchAnswersItsRequestsInOrderAndSkipsItsNotifications(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(
            json_encode([
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => 'two', 'method' => 'tools/list'],
                ['jsonrpc' => '2.0', 'id' => 3, 'result' => []],
                ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'no/such/method'],
            ], JSON_THROW_ON_ERROR),
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        $responses = $this->decode($answer);
        self::assertTrue(array_is_list($responses));
        self::assertCount(3, $responses);
        self::assertSame([1, 'two', 4], array_column($responses, 'id'));
        self::assertStringStartsWith('[{"jsonrpc":"2.0","id":1,"result":{}}', $answer->getBody());
        self::assertSame(-32601, $this->arrayAt($this->arrayAt($responses, 2), 'error')['code']);
        self::assertSame([$session->getId()], $this->kit->sessions->touched);
    }

    public function testABatchOfOnlyNotificationsAndClientResponsesIsAccepted(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(
            '[{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","id":1,"result":{}}]',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Accepted, $answer->getStatus());
        self::assertSame('', $answer->getBody());
    }

    public function testAnEmptyBatchIsABadRequestWithANullId(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(" \n[]", $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, 'batch');
    }

    public function testInitializeInsideABatchIsAnInvalidRequestForThatElement(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(
            '[{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}},{"jsonrpc":"2.0","id":2,"method":"ping"}]',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertSame('', $answer->getSessionId());
        self::assertCount(1, $this->kit->sessions->sessions);
        $responses = $this->decode($answer);
        $first = $this->arrayAt($responses, 0);
        self::assertSame(1, $first['id']);
        $error = $this->arrayAt($first, 'error');
        self::assertSame(-32600, $error['code']);
        self::assertIsString($error['message']);
        self::assertStringContainsString('initialize must be sent alone', $error['message']);
        self::assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], $this->arrayAt($responses, 1));
    }

    public function testAnInvalidElementOfABatchIsAnsweredWithANullIdAndTheOthersAreStillHandled(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(
            '[1,{"jsonrpc":"2.0","id":2,"method":"ping"},{"jsonrpc":"1.0","id":3,"method":"ping"}]',
            $this->kit->context($session->getId())
        );

        $responses = $this->decode($answer);
        self::assertSame([null, 2, null], array_column($responses, 'id'));
        self::assertSame(-32600, $this->arrayAt($this->arrayAt($responses, 0), 'error')['code']);
        self::assertSame(-32600, $this->arrayAt($this->arrayAt($responses, 2), 'error')['code']);
    }

    public function testAFailingRequestInABatchDoesNotStopTheOthers(): void
    {
        $server = $this->kit->server(null, ['tools/list' => $this->failingHandler()]);
        $session = $this->kit->openSession();

        $answer = $server->handle(
            '[{"jsonrpc":"2.0","id":1,"method":"tools/list"},{"jsonrpc":"2.0","id":2,"method":"ping"}]',
            $this->kit->context($session->getId())
        );

        $responses = $this->decode($answer);
        self::assertSame(-32603, $this->arrayAt($this->arrayAt($responses, 0), 'error')['code']);
        self::assertSame([], $this->arrayAt($responses, 1)['result']);
    }

    public function testABodyLongerThanTheLimitIsRefusedBeforeItIsRead(): void
    {
        $server = $this->kit->server(null, [], 64);
        $session = $this->kit->openSession();
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . str_repeat('x', 64) . '"';

        $answer = $server->handle($body, $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, 'The request body is too large.');
        self::assertSame([], $this->kit->sessions->touched);
    }

    public function testABodyAtTheLimitIsServed(): void
    {
        $body = $this->request('ping', 1);
        $server = $this->kit->server(null, [], strlen($body));
        $session = $this->kit->openSession();

        $answer = $server->handle($body, $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
    }

    public function testABatchLargerThanTheLimitIsABadRequestWithANullId(): void
    {
        $server = $this->kit->server(null, [], 1048576, 2);
        $session = $this->kit->openSession();

        $answer = $server->handle(
            '[' . implode(',', [$this->request('ping', 1), $this->request('ping', 2), $this->request('ping', 3)]) . ']',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, 'at most 2');
        self::assertSame([], $this->kit->sessions->touched);
    }

    public function testABatchAtTheLimitIsServed(): void
    {
        $server = $this->kit->server(null, [], 1048576, 2);
        $session = $this->kit->openSession();

        $answer = $server->handle(
            '[' . $this->request('ping', 1) . ',' . $this->request('ping', 2) . ']',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertCount(2, $this->decode($answer));
    }

    public function testToolsCallSentAsANotificationRunsNothingAndIsAccepted(): void
    {
        $invoker = new FakeInvoker(
            static fn (InvocationInterface $invocation): InvocationResultInterface => new InvocationResult(
                InvocationOutcome::Succeeded,
                1.0
            )
        );
        $server = $this->kit->server([$this->kit->executeAbility($invoker)]);
        $session = $this->kit->openSession();

        $answer = $server->handle(
            '{"jsonrpc":"2.0","method":"tools/call","params":{"name":"execute_ability",'
            . '"arguments":{"name":"catalog.product_repository.get_list"}}}',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Accepted, $answer->getStatus());
        self::assertSame('', $answer->getBody());
        self::assertSame([], $invoker->invocations);
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function notificationAcceptance(): array
    {
        return ['a handler that accepts notifications' => [true, 1], 'a request-only handler' => [false, 0]];
    }

    #[DataProvider('notificationAcceptance')]
    public function testANotificationRunsOnlyAHandlerThatAcceptsIt(bool $accepts, int $runs): void
    {
        $handler = $this->countingHandler($accepts);
        $server = $this->kit->server(null, ['custom/method' => $handler]);
        $session = $this->kit->openSession();

        $answer = $server->handle('{"jsonrpc":"2.0","method":"custom/method"}', $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Accepted, $answer->getStatus());
        self::assertCount($runs, $handler);
    }

    public function testARequestOnlyHandlerStillAnswersItsRequests(): void
    {
        $handler = $this->countingHandler(false);
        $server = $this->kit->server(null, ['custom/method' => $handler]);
        $session = $this->kit->openSession();

        $answer = $server->handle($this->request('custom/method', 3), $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertCount(1, $handler);
    }

    public function testInitializeSentAsANotificationOpensNoSession(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->handle(
            '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            $this->kit->context($session->getId())
        );

        self::assertSame(AnswerStatus::Accepted, $answer->getStatus());
        self::assertCount(1, $this->kit->sessions->sessions);
    }

    public function testABatchOfOnlyInitializeWithoutASessionIsAnsweredPerElement(): void
    {
        $answer = $this->server->handle(
            '[{"jsonrpc":"2.0","id":"init","method":"initialize","params":{"protocolVersion":"2025-06-18"}}]',
            $this->kit->context()
        );

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertSame('', $answer->getSessionId());
        self::assertSame([], $this->kit->sessions->sessions);
        $responses = $this->decode($answer);
        self::assertCount(1, $responses);
        $this->assertSentAlone($this->arrayAt($responses, 0), 'init');
    }

    public function testABatchWithInitializeAndNoSessionAnswersEveryRequestWithItsOwnId(): void
    {
        $answer = $this->server->handle(
            '[{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}},'
            . '{"jsonrpc":"2.0","method":"notifications/initialized"},'
            . '{"jsonrpc":"2.0","id":2,"method":"ping"}]',
            $this->kit->context()
        );

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        self::assertSame([], $this->kit->sessions->sessions);
        $responses = $this->decode($answer);
        self::assertSame([1, 2], array_column($responses, 'id'));
        $this->assertSentAlone($this->arrayAt($responses, 0), 1);
        $sessionError = $this->arrayAt($this->arrayAt($responses, 1), 'error');
        self::assertSame(-32600, $sessionError['code']);
        self::assertIsString($sessionError['message']);
        self::assertStringContainsString('Mcp-Session-Id', $sessionError['message']);
    }

    public function testABatchWithInitializeAndAnUnknownSessionKeepsTheNotFoundStatus(): void
    {
        $answer = $this->server->handle(
            '[{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}},{"jsonrpc":"2.0","id":2,"method":"ping"}]',
            $this->kit->context(str_repeat('0', 32))
        );

        self::assertSame(AnswerStatus::NotFound, $answer->getStatus());
        $responses = $this->decode($answer);
        $this->assertSentAlone($this->arrayAt($responses, 0), 1);
        self::assertSame(2, $this->arrayAt($responses, 1)['id']);
    }

    public function testAFailureLookingUpTheSessionAnswersAnInternalErrorWithTheRequestsId(): void
    {
        $session = $this->kit->openSession();
        $this->kit->sessions->findFailure = new \RuntimeException('database password leaked');

        $answer = $this->server->handle($this->request('ping', 'p-9'), $this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        $this->assertError($answer, 'p-9', -32603, 'Internal error');
        self::assertStringNotContainsString('database password', $answer->getBody());
        self::assertSame(
            ['The MCP session could not be checked: database password leaked'],
            $this->kit->logger->messages('error')
        );
    }

    public function testASessionRefusalIsStillABadRequestWithANullIdWhenNothingFailed(): void
    {
        $answer = $this->server->handle($this->request('ping', 'p-9'), $this->kit->context());

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        $this->assertError($answer, null, -32600, 'Mcp-Session-Id');
        self::assertSame([], $this->kit->logger->messages('error'));
    }

    public function testAFailureLookingUpTheSessionOfABatchIsLeftToTheTransport(): void
    {
        $session = $this->kit->openSession();
        $this->kit->sessions->findFailure = new \RuntimeException('gone');

        $this->expectException(\RuntimeException::class);

        $this->server->handle('[' . $this->request('ping', 1) . ']', $this->kit->context($session->getId()));
    }

    public function testABodyLimitBelowOneByteIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->kit->server(null, [], 0);
    }

    public function testEndingTheCallersOwnSessionDeletesItAndAnswersNoContent(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->endSession($this->kit->context($session->getId()));

        self::assertSame(AnswerStatus::NoContent, $answer->getStatus());
        self::assertSame('', $answer->getBody());
        self::assertSame([$session->getId()], $this->kit->sessions->deleted);
        self::assertSame(
            AnswerStatus::NotFound,
            $this->server->handle($this->request('ping', 1), $this->kit->context($session->getId()))->getStatus()
        );
    }

    public function testEndingAnotherCallersSessionIsNotFoundAndLeavesIt(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->server->endSession($this->kit->context($session->getId(), '', $this->kit->admin(2)));

        self::assertSame(AnswerStatus::NotFound, $answer->getStatus());
        self::assertSame([], $this->kit->sessions->deleted);
        self::assertNotNull($this->kit->sessions->find($session->getId()));
    }

    public function testEndingASessionWithoutASessionIdIsABadRequest(): void
    {
        $answer = $this->server->endSession($this->kit->context());

        self::assertSame(AnswerStatus::BadRequest, $answer->getStatus());
        self::assertSame([], $this->kit->sessions->deleted);
    }

    public function testToolsCallRunsTheToolInTheCallersSessionWithItsClient(): void
    {
        $session = $this->kit->sessions->add(
            str_repeat('cd', 16),
            $this->kit->admin(),
            new Client('Other Client', '1.5')
        );
        $seen = [];
        $server = $this->kit->server([
            new FakeTool(
                'fake_tool',
                static function (array $arguments, CallContext $context) use (&$seen): array {
                    $seen[] = $context;

                    return ['content' => [], 'isError' => false];
                }
            ),
        ]);

        $server->handle(
            $this->request('tools/call', 1, ['name' => 'fake_tool', 'arguments' => ['a' => 1]]),
            $this->kit->context($session->getId())
        );

        self::assertCount(1, $seen);
        self::assertSame($session, $seen[0]->getSession());
        self::assertSame('Other Client', $session->getClient()->getName());
        self::assertSame(1, $seen[0]->getCaller()->getUserId());
    }

    /**
     * A JSON-RPC request body
     *
     * @param string $method
     * @param int|string $id
     * @param array<string,mixed> $params
     * @return string
     */
    private function request(string $method, int|string $id, array $params = []): string
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== []) {
            $message['params'] = $params;
        }

        return json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * Assert the body is one JSON-RPC error with this id and code, whose message holds the text
     *
     * @param ProtocolAnswer $answer
     * @param int|string|null $id
     * @param int $code
     * @param string $text
     * @return void
     */
    private function assertError(ProtocolAnswer $answer, int|string|null $id, int $code, string $text): void
    {
        $body = $this->decode($answer);
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame($id, $body['id']);
        self::assertArrayNotHasKey('result', $body);
        $error = $this->arrayAt($body, 'error');
        self::assertSame($code, $error['code']);
        self::assertIsString($error['message']);
        self::assertStringContainsString($text, $error['message']);
    }

    /**
     * Assert a batch response is the Invalid Request error of an `initialize` sent in a batch, with this id
     *
     * @param array<mixed> $response
     * @param int|string $id
     * @return void
     */
    private function assertSentAlone(array $response, int|string $id): void
    {
        self::assertSame($id, $response['id']);
        $error = $this->arrayAt($response, 'error');
        self::assertSame(-32600, $error['code']);
        self::assertIsString($error['message']);
        self::assertStringContainsString('initialize must be sent alone', $error['message']);
    }

    /**
     * The decoded body
     *
     * @param ProtocolAnswer $answer
     * @return array<mixed>
     */
    private function decode(ProtocolAnswer $answer): array
    {
        $decoded = json_decode($answer->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The array under a key
     *
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

    /**
     * A handler that throws an error whose message must not reach the client
     *
     * @param bool $acceptsNotification
     * @return MethodHandlerInterface
     */
    private function failingHandler(bool $acceptsNotification = false): MethodHandlerInterface
    {
        return new class ($acceptsNotification) implements MethodHandlerInterface {
            /**
             * @param bool $acceptsNotification
             */
            public function __construct(
                private readonly bool $acceptsNotification
            ) {
            }

            /**
             * @inheritDoc
             */
            public function handle(Message $message, CallContext $context): Reply
            {
                throw new \RuntimeException('database password leaked');
            }

            /**
             * @inheritDoc
             */
            public function acceptsNotification(): bool
            {
                return $this->acceptsNotification;
            }
        };
    }

    /**
     * A handler that counts its calls and answers an empty result
     *
     * @param bool $acceptsNotification
     * @return MethodHandlerInterface&\Countable
     */
    private function countingHandler(bool $acceptsNotification): MethodHandlerInterface&\Countable
    {
        return new class ($acceptsNotification, $this->kit) implements MethodHandlerInterface, \Countable {
            /**
             * @var int
             */
            private int $calls = 0;

            /**
             * @param bool $acceptsNotification
             * @param ProtocolKit $kit
             */
            public function __construct(
                private readonly bool $acceptsNotification,
                private readonly ProtocolKit $kit
            ) {
            }

            /**
             * @inheritDoc
             */
            public function handle(Message $message, CallContext $context): Reply
            {
                $this->calls++;

                return $this->kit->replyFactory()->create();
            }

            /**
             * @inheritDoc
             */
            public function acceptsNotification(): bool
            {
                return $this->acceptsNotification;
            }

            /**
             * How many times the handler ran
             *
             * @return int
             */
            public function count(): int
            {
                return $this->calls;
            }
        };
    }
}
