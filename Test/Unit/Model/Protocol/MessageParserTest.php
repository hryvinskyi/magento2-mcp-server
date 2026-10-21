<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Exception\RequestRejectedException;
use Hryvinskyi\McpServer\Model\Protocol\MessageKind;
use Hryvinskyi\McpServer\Model\Protocol\MessageParser;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reading a body as JSON-RPC 2.0: every error code, and the three kinds of message told apart
 */
class MessageParserTest extends TestCase
{
    private const PING = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

    /**
     * @var MessageParser
     */
    private MessageParser $parser;

    protected function setUp(): void
    {
        $this->parser = (new ProtocolKit($this))->parser();
    }

    public function testARequestHasItsMethodIdAndParams(): void
    {
        $body = $this->parser->parse(
            '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"x","arguments":{"0":"zero","a":1}}}'
        );

        self::assertFalse($body->isBatch());
        $message = $body->getMessages()[0];
        self::assertSame(MessageKind::Request, $message->getKind());
        self::assertSame('tools/call', $message->getMethod());
        self::assertSame(7, $message->getId());
        self::assertSame(['name' => 'x', 'arguments' => ['0' => 'zero', 'a' => 1]], $message->getParams());
    }

    public function testAStringIdIsKeptAsAString(): void
    {
        $message = $this->parser->parse('{"jsonrpc":"2.0","id":"abc","method":"ping"}')->getMessages()[0];

        self::assertSame('abc', $message->getId());
        self::assertSame([], $message->getParams());
    }

    public function testAMessageWithoutAnIdIsANotification(): void
    {
        $message = $this->parser->parse('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"x":1}}')
            ->getMessages()[0];

        self::assertSame(MessageKind::Notification, $message->getKind());
        self::assertSame('notifications/cancelled', $message->getMethod());
        self::assertNull($message->getId());
        self::assertSame(['x' => 1], $message->getParams());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clientResponses(): array
    {
        return [
            'result' => ['{"jsonrpc":"2.0","id":3,"result":{"ok":true}}'],
            'error' => ['{"jsonrpc":"2.0","id":3,"error":{"code":-32000,"message":"no"}}'],
            'empty result' => ['{"jsonrpc":"2.0","id":3,"result":{}}'],
        ];
    }

    #[DataProvider('clientResponses')]
    public function testAMessageWithAResultOrErrorAndNoMethodIsAClientResponse(string $json): void
    {
        $message = $this->parser->parse($json)->getMessages()[0];

        self::assertSame(MessageKind::Response, $message->getKind());
        self::assertSame('', $message->getMethod());
        self::assertSame(3, $message->getId());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJson(): array
    {
        return [
            'truncated' => ['{"jsonrpc":"2.0",'],
            'empty' => [''],
            'not json' => ['hello'],
        ];
    }

    #[DataProvider('invalidJson')]
    public function testABodyThatIsNotJsonIsAParseError(string $body): void
    {
        $this->assertRejected($body, ErrorCode::ParseError);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEnvelopes(): array
    {
        return [
            'a number' => ['42'],
            'a string' => ['"ping"'],
            'null' => ['null'],
            'an empty object' => ['{}'],
            'no jsonrpc' => ['{"id":1,"method":"ping"}'],
            'jsonrpc 1.0' => ['{"jsonrpc":"1.0","id":1,"method":"ping"}'],
            'method not a string' => ['{"jsonrpc":"2.0","id":1,"method":5}'],
            'empty method' => ['{"jsonrpc":"2.0","id":1,"method":""}'],
            'id null' => ['{"jsonrpc":"2.0","id":null,"method":"ping"}'],
            'id a float' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}'],
            'id a boolean' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}'],
            'id an object' => ['{"jsonrpc":"2.0","id":{},"method":"ping"}'],
            'params a list' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":[1,2]}'],
            'params a string' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":"x"}'],
            'neither method nor result' => ['{"jsonrpc":"2.0","id":1}'],
        ];
    }

    #[DataProvider('invalidEnvelopes')]
    public function testASingleInvalidMessageIsAnInvalidRequest(string $body): void
    {
        $this->assertRejected($body, ErrorCode::InvalidRequest);
    }

    public function testAnEmptyBatchIsAnInvalidRequest(): void
    {
        $this->assertRejected('[]', ErrorCode::InvalidRequest);
    }

    public function testABatchKeepsItsMessagesInOrderAndMarksInvalidElements(): void
    {
        $body = $this->parser->parse(
            "\n [1, {\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}, [], {\"jsonrpc\":\"2.0\",\"method\":\"n\"},"
            . ' {"jsonrpc":"2.0","id":null,"method":"ping"}, {"jsonrpc":"2.0","id":2,"result":{}}]'
        );

        self::assertTrue($body->isBatch());
        self::assertSame(
            [
                MessageKind::Invalid,
                MessageKind::Request,
                MessageKind::Invalid,
                MessageKind::Notification,
                MessageKind::Invalid,
                MessageKind::Response,
            ],
            array_map(static fn ($message) => $message->getKind(), $body->getMessages())
        );
        self::assertStringContainsString('JSON object', $body->getMessages()[0]->getError());
        self::assertNull($body->getMessages()[0]->getId());
    }

    public function testABatchOfOneIsStillABatch(): void
    {
        $body = $this->parser->parse('[{"jsonrpc":"2.0","id":1,"method":"ping"}]');

        self::assertTrue($body->isBatch());
        self::assertCount(1, $body->getMessages());
    }

    public function testABatchOfTheMostMessagesAllowedIsRead(): void
    {
        $body = (new ProtocolKit($this))->parser(3)->parse('[' . implode(',', array_fill(0, 3, self::PING)) . ']');

        self::assertCount(3, $body->getMessages());
    }

    public function testABatchOfMoreMessagesThanAllowedIsAnInvalidRequest(): void
    {
        $parser = (new ProtocolKit($this))->parser(3);

        try {
            $parser->parse('[' . implode(',', array_fill(0, 4, self::PING)) . ']');
            self::fail('The batch must be refused.');
        } catch (RequestRejectedException $rejection) {
            self::assertSame(AnswerStatus::BadRequest, $rejection->getStatus());
            self::assertSame(ErrorCode::InvalidRequest, $rejection->getErrorCode());
            self::assertStringContainsString('at most 3 messages', $rejection->getMessage());
        }
    }

    public function testTheDefaultBatchLimitIsTwenty(): void
    {
        $twenty = $this->parser->parse('[' . implode(',', array_fill(0, 20, self::PING)) . ']');

        self::assertCount(20, $twenty->getMessages());
        $this->assertRejected('[' . implode(',', array_fill(0, 21, self::PING)) . ']', ErrorCode::InvalidRequest);
    }

    public function testABatchLimitBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProtocolKit($this))->parser(0);
    }

    public function testParamsNestedUpToSixtyFourLevelsAreRead(): void
    {
        $message = $this->parser->parse($this->nested(61))->getMessages()[0];

        self::assertSame(MessageKind::Request, $message->getKind());
    }

    public function testABodyNestedDeeperThanSixtyFourLevelsIsAnInvalidRequest(): void
    {
        try {
            $this->parser->parse($this->nested(62));
            self::fail('The body must be refused.');
        } catch (RequestRejectedException $rejection) {
            self::assertSame(ErrorCode::InvalidRequest, $rejection->getErrorCode());
            self::assertStringContainsString('64 levels', $rejection->getMessage());
        }
    }

    /**
     * A request whose params hold lists nested this many levels below the params object
     *
     * The message object, its params object and the innermost scalar count as levels too, so the body is nested
     * $lists + 3 levels deep.
     *
     * @param int $lists
     * @return string
     */
    private function nested(int $lists): string
    {
        return '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"deep":'
            . str_repeat('[', $lists) . '1' . str_repeat(']', $lists) . '}}';
    }

    public function testParamsThatHoldAnInstanceKeyAreKeptAsPlainData(): void
    {
        $message = $this->parser->parse(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call",'
            . '"params":{"instance":"Magento\\\\Framework\\\\App\\\\State"}}'
        )->getMessages()[0];

        self::assertSame(['instance' => 'Magento\Framework\App\State'], $message->getParams());
    }

    /**
     * Assert the body is refused as a whole as bad input with this code
     *
     * @param string $body
     * @param ErrorCode $code
     * @return void
     */
    private function assertRejected(string $body, ErrorCode $code): void
    {
        try {
            $this->parser->parse($body);
            self::fail('The body must be refused.');
        } catch (RequestRejectedException $rejection) {
            self::assertSame(AnswerStatus::BadRequest, $rejection->getStatus());
            self::assertSame($code, $rejection->getErrorCode());
            self::assertNotSame('', $rejection->getMessage());
        }
    }
}
