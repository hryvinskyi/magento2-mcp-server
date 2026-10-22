<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Protocol;

use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Server;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Authorization\Model\UserContextInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The server the container builds: its method handlers, tools, outcome answers, protocol versions and session store
 * are all wired, so a session can be opened, used and ended
 */
#[DbIsolation(true)]
class ServerTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use SwitchesGraphQlSourceOff;

    /**
     * @var Server
     */
    private Server $server;

    protected function setUp(): void
    {
        $this->server = $this->service(Server::class);
    }

    public function testASessionIsOpenedUsedAndEndedThroughTheWiredHandlers(): void
    {
        $opened = $this->server->handle(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26",'
            . '"clientInfo":{"name":"Integration","version":"1"}}}',
            $this->context('')
        );
        self::assertSame(AnswerStatus::Ok, $opened->getStatus());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $opened->getSessionId());
        self::assertSame('2025-03-26', $this->arrayAt($this->decode($opened), 'result')['protocolVersion']);
        $session = $opened->getSessionId();

        $initialized = $this->server->handle(
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            $this->context($session, '2025-03-26')
        );
        self::assertSame(AnswerStatus::Accepted, $initialized->getStatus());

        $batch = $this->server->handle(
            '[{"jsonrpc":"2.0","id":2,"method":"ping"},{"jsonrpc":"2.0","id":3,"method":"tools/list"}]',
            $this->context($session)
        );
        self::assertStringStartsWith('[{"jsonrpc":"2.0","id":2,"result":{}}', $batch->getBody());
        $tools = $this->arrayAt($this->arrayAt($this->arrayAt($this->decode($batch), 1), 'result'), 'tools');
        self::assertSame(
            ['discover_abilities', 'get_ability_info', 'execute_ability'],
            array_column($tools, 'name')
        );

        $ended = $this->server->endSession($this->context($session));
        self::assertSame(AnswerStatus::NoContent, $ended->getStatus());
        self::assertSame(
            AnswerStatus::NotFound,
            $this->server->handle('{"jsonrpc":"2.0","id":4,"method":"ping"}', $this->context($session))->getStatus()
        );
    }

    public function testExecuteAbilityAnswersAnUnknownNameAsAToolErrorAndADenialAsAJsonRpcError(): void
    {
        $session = $this->server->handle(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            $this->context('')
        )->getSessionId();

        $unknown = $this->decode($this->server->handle(
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"execute_ability",'
            . '"arguments":{"name":"catalog.nothing.here"}}}',
            $this->context($session)
        ));
        $denied = $this->decode($this->server->handle(
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"execute_ability",'
            . '"arguments":{"name":"catalog.product_repository.save","arguments":{"product":{"sku":"x"}}}}}',
            $this->context($session)
        ));

        self::assertTrue($this->arrayAt($unknown, 'result')['isError']);
        $error = $this->arrayAt($denied, 'error');
        self::assertSame(-32003, $error['code']);
        self::assertSame(
            ['ability' => 'catalog.product_repository.save', 'reason' => 'read_only_mode'],
            $error['data']
        );
    }

    public function testTheWiredServerRefusesABodyOverOneMebibyteAndABatchOverTwentyMessages(): void
    {
        $session = $this->server->handle(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            $this->context('')
        )->getSessionId();
        $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        $tooLarge = $this->server->handle(
            '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . str_repeat('x', 1048576) . '"}}',
            $this->context($session)
        );
        $twenty = $this->server->handle('[' . implode(',', array_fill(0, 20, $ping)) . ']', $this->context($session));
        $twentyOne = $this->server->handle(
            '[' . implode(',', array_fill(0, 21, $ping)) . ']',
            $this->context($session)
        );

        self::assertSame(AnswerStatus::BadRequest, $tooLarge->getStatus());
        $refusal = $this->arrayAt($this->decode($tooLarge), 'error');
        self::assertSame('The request body is too large.', $refusal['message']);
        self::assertSame(AnswerStatus::Ok, $twenty->getStatus());
        self::assertSame(AnswerStatus::BadRequest, $twentyOne->getStatus());
        self::assertSame(-32600, $this->arrayAt($this->decode($twentyOne), 'error')['code']);
    }

    /**
     * What the transport knows of a request by the admin with id 1
     *
     * @param string $sessionId
     * @param string $protocolVersion
     * @return TransportContext
     */
    private function context(string $sessionId, string $protocolVersion = ''): TransportContext
    {
        $objectManager = Bootstrap::getObjectManager();
        $context = $objectManager->create(TransportContext::class, [
            'caller' => $this->service(CallerInterfaceFactory::class)->create([
                'userType' => UserContextInterface::USER_TYPE_ADMIN,
                'userId' => 1,
            ]),
            'transport' => 'http',
            'sessionId' => $sessionId,
            'protocolVersion' => $protocolVersion,
        ]);
        self::assertInstanceOf(TransportContext::class, $context);

        return $context;
    }

    /**
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
     * A service from the container, of the class asked for
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
