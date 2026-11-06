<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\JsonRpcResponses;
use Hryvinskyi\McpServer\Model\Stdio\MessageLines;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line read the way the protocol server reads it: the answer a failed line may get under JSON-RPC's rules, the
 * server's refusal of a whole line addressed to the line's requests, and whether it opens or confirms a session
 */
class MessageLinesTest extends TestCase
{
    private const INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
    private const MISSING_SESSION = 'Bad Request: the Mcp-Session-Id header is missing. Send initialize first.';
    private const UNKNOWN_SESSION = 'Session not found: it is unknown or expired. Send initialize to open a new one.';

    /**
     * @return array<string, array{string, string}>
     */
    public static function failedLines(): array
    {
        return [
            'a request keeps its integer id' => [
                '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{}}',
                self::error('7'),
            ],
            'a request keeps its string id' => ['{"jsonrpc":"2.0","id":"x","method":"ping"}', self::error('"x"')],
            'a notification gets nothing' => [self::INITIALIZED, ''],
            'a client response gets nothing' => ['{"jsonrpc":"2.0","id":4,"result":{}}', ''],
            'a client error response gets nothing' => ['{"jsonrpc":"2.0","id":4,"error":{"code":1,"message":"m"}}', ''],
            'a batch gets the errors of its requests, in order' => [
                '[{"jsonrpc":"2.0","id":2,"method":"ping"},' . self::INITIALIZED . ',{"jsonrpc":"2.0","id":3,'
                . '"method":"tools/list"},{"jsonrpc":"2.0","id":5,"result":{}}]',
                '[' . self::error('2') . ',' . self::error('3') . ']',
            ],
            'a batch without requests gets nothing' => [
                '[' . self::INITIALIZED . ',{"jsonrpc":"2.0","id":5,"result":{}}]',
                '',
            ],
            'an invalid element of a batch is not answered' => [
                '[{"jsonrpc":"2.0","id":2,"method":"ping"},{"id":9}]',
                '[' . self::error('2') . ']',
            ],
            'not JSON names no id' => ['{"jsonrpc":"2.0","id":', self::error('null')],
            'JSON that is no message names no id' => ['"hello"', self::error('null')],
            'an id of another type names no id' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}', self::error('null')],
            'an empty batch names no id' => ['[]', self::error('null')],
            'JSON nested too deep names no id' => [str_repeat('[', 70) . str_repeat(']', 70), self::error('null')],
        ];
    }

    #[DataProvider('failedLines')]
    public function testAFailedLineIsAnsweredTheWayJsonRpcAllows(string $line, string $answer): void
    {
        self::assertSame($answer, $this->lines()->failureAnswer($line));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function answersToAddress(): array
    {
        $missing = static fn (string $id): string => self::refusal($id, -32600, self::MISSING_SESSION);
        $unknown = static fn (string $id): string => self::refusal($id, -32600, self::UNKNOWN_SESSION);
        $withData = static fn (string $id): string => '{"jsonrpc":"2.0","id":' . $id . ',"error":{"code":-32000,'
            . '"message":"Busy","data":{"retry":{},"after":[1.0,"s"],"why":{"0":"queue"}}}}';

        return [
            'a single request gets the refusal with its id, code and message kept' => [
                '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{}}',
                $missing('null'),
                $missing('7'),
            ],
            'a request with a string id keeps it' => [
                '{"jsonrpc":"2.0","id":"a-1","method":"ping"}',
                $unknown('null'),
                $unknown('"a-1"'),
            ],
            'an Internal error with the id null gets the id of the request' => [
                '{"jsonrpc":"2.0","id":3,"method":"tools/list"}',
                self::error('null'),
                self::error('3'),
            ],
            'a batch gets the refusal once for each request, in order' => [
                '[{"jsonrpc":"2.0","id":2,"method":"ping"},' . self::INITIALIZED . ',{"jsonrpc":"2.0","id":"b",'
                . '"method":"tools/list"}]',
                $unknown('null'),
                '[' . $unknown('2') . ',' . $unknown('"b"') . ']',
            ],
            'any integer code is passed on, and the data object as it came, empty objects included' => [
                '{"jsonrpc":"2.0","id":4,"method":"ping"}',
                $withData('null'),
                $withData('4'),
            ],
            'a line of notifications gets nothing' => [self::INITIALIZED, $missing('null'), ''],
            'a batch of notifications and client responses gets nothing' => [
                '[' . self::INITIALIZED . ',{"jsonrpc":"2.0","id":5,"result":{}}]',
                $unknown('null'),
                '',
            ],
        ];
    }

    #[DataProvider('answersToAddress')]
    public function testARefusalWithTheIdNullIsAddressedToTheRequestsOfTheLine(
        string $line,
        string $body,
        string $addressed
    ): void {
        self::assertSame($addressed, $this->lines()->addressed($line, $body));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function answersKeptAsTheyCame(): array
    {
        $request = '{"jsonrpc":"2.0","id":7,"method":"ping"}';

        return [
            'a result' => [$request, '{"jsonrpc":"2.0","id":7,"result":{}}'],
            'an error that already has an id' => [
                $request,
                '{"jsonrpc":"2.0","id":7,"error":{"code":-32601,"message":"Method not found"}}',
            ],
            'a batch answer' => [
                '[' . $request . ']',
                '[{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}]',
            ],
            'an empty body' => [$request, ''],
            'invalid JSON' => [$request, '{"jsonrpc":"2.0","id":null,"error":'],
            'an error whose code is not an integer' => [
                $request,
                '{"jsonrpc":"2.0","id":null,"error":{"code":"-32600","message":"Invalid Request"}}',
            ],
            'an error whose message is not a string' => [
                $request,
                '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":["Invalid Request"]}}',
            ],
            'an error whose data is a string' => [
                $request,
                '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error","data":"x"}}',
            ],
            'an error whose data is a JSON array' => [
                $request,
                '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error","data":[]}}',
            ],
            'an error whose data is null' => [
                $request,
                '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error","data":null}}',
            ],
            'an error of another JSON-RPC version' => [
                $request,
                '{"jsonrpc":"1.0","id":null,"error":{"code":-32603,"message":"Internal error"}}',
            ],
            'an error that is not written the way the server writes a refusal' => [
                $request,
                '{"id":null,"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"}}',
            ],
            'a refusal of a line that is not JSON' => ['{"jsonrpc":"2.0","id":7,', self::error('null')],
            'a refusal of a line nested too deep' => [str_repeat('[', 70) . str_repeat(']', 70), self::error('null')],
            'a refusal of a request whose id is null' => [
                '{"jsonrpc":"2.0","id":null,"method":"ping"}',
                self::refusal('null', -32600, self::MISSING_SESSION),
            ],
        ];
    }

    #[DataProvider('answersKeptAsTheyCame')]
    public function testAnyOtherAnswerIsWrittenAsItCame(string $line, string $body): void
    {
        self::assertSame($body, $this->lines()->addressed($line, $body));
    }

    public function testTheRefusalsReadHereAreWrittenTheWayTheServerWritesThem(): void
    {
        $answers = (new ProtocolKit($this))->answers();

        self::assertSame(
            self::refusal('null', -32600, self::MISSING_SESSION),
            $answers->error(AnswerStatus::BadRequest, ErrorCode::InvalidRequest, self::MISSING_SESSION)->getBody()
        );
        self::assertSame(
            self::refusal('null', -32600, self::UNKNOWN_SESSION),
            $answers->error(AnswerStatus::NotFound, ErrorCode::InvalidRequest, self::UNKNOWN_SESSION)->getBody()
        );
        self::assertSame(
            self::error('null'),
            $answers->error(AnswerStatus::Ok, ErrorCode::InternalError, 'Internal error')->getBody()
        );
    }

    public function testALargeResultIsWrittenAsItCame(): void
    {
        $body = '{"jsonrpc":"2.0","id":7,"result":{"text":"' . str_repeat('x', 1048576) . '"}}';

        self::assertSame($body, $this->lines()->addressed('{"jsonrpc":"2.0","id":7,"method":"ping"}', $body));
    }

    public function testOnlyASingleInitializeRequestOpensASession(): void
    {
        $lines = $this->lines();

        self::assertTrue($lines->opensSession('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'));
        self::assertFalse($lines->opensSession('{"jsonrpc":"2.0","method":"initialize"}'));
        self::assertFalse($lines->opensSession('[{"jsonrpc":"2.0","id":1,"method":"initialize"}]'));
        self::assertFalse($lines->opensSession('{"jsonrpc":"2.0","id":1,"method":"ping"}'));
        self::assertFalse($lines->opensSession('not json'));
    }

    public function testOnlyASingleInitializedNotificationConfirmsASession(): void
    {
        $lines = $this->lines();

        self::assertTrue($lines->confirmsSession(self::INITIALIZED));
        self::assertFalse($lines->confirmsSession('{"jsonrpc":"2.0","id":3,"method":"notifications/initialized"}'));
        self::assertFalse($lines->confirmsSession('[' . self::INITIALIZED . ']'));
        self::assertFalse($lines->confirmsSession('{"jsonrpc":"2.0","method":"notifications/cancelled"}'));
    }

    /**
     * @param string $id The id as JSON
     * @return string
     */
    private static function error(string $id): string
    {
        return self::refusal($id, -32603, 'Internal error');
    }

    /**
     * An error answer written the way the server writes one, without data
     *
     * @param string $id The id as JSON
     * @param int $code
     * @param string $message
     * @return string
     */
    private static function refusal(string $id, int $code, string $message): string
    {
        return '{"jsonrpc":"2.0","id":' . $id . ',"error":{"code":' . $code . ',"message":"' . $message . '"}}';
    }

    /**
     * @return MessageLines
     */
    private function lines(): MessageLines
    {
        $kit = new ProtocolKit($this);

        return new MessageLines($kit->parser(), new JsonRpcResponses(), $kit->answers());
    }
}
