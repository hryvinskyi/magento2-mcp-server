<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol;

use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\MessageKind;
use Hryvinskyi\McpServer\Model\Protocol\ParsedBody;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Reply;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The protocol's value objects refuse what contradicts them, and their withers leave the original as it was
 */
class ValueObjectsTest extends TestCase
{
    private const SESSION = 'ffffffffffffffffffffffffffffffff';

    /**
     * @return array<string, array{\Closure(): mixed}>
     */
    public static function contradictions(): array
    {
        return [
            'request without a method' => [static fn () => new Message(MessageKind::Request, '', 1)],
            'request without an id' => [static fn () => new Message(MessageKind::Request, 'ping')],
            'notification with an id' => [static fn () => new Message(MessageKind::Notification, 'n', 1)],
            'notification without a method' => [static fn () => new Message(MessageKind::Notification)],
            'response with a method' => [static fn () => new Message(MessageKind::Response, 'ping', 1)],
            'invalid without a reason' => [static fn () => new Message(MessageKind::Invalid)],
            'invalid with an id' => [static fn () => new Message(MessageKind::Invalid, '', 1, 'bad')],
            'request with a reason' => [static fn () => new Message(MessageKind::Request, 'ping', 1, 'bad')],
            'error reply without a message' => [static fn () => new Reply(ErrorCode::InternalError)],
            'result reply with a message' => [static fn () => new Reply(null, 'oops')],
            'error reply opening a session' => [
                static fn () => new Reply(ErrorCode::InternalError, 'oops', self::SESSION),
            ],
            'reply with a malformed session' => [static fn () => new Reply(null, '', 'abc')],
            'accepted answer with a body' => [static fn () => new ProtocolAnswer(AnswerStatus::Accepted, '{}')],
            'no-content answer with a body' => [static fn () => new ProtocolAnswer(AnswerStatus::NoContent, '{}')],
            'answer with a malformed session' => [static fn () => new ProtocolAnswer(AnswerStatus::Ok, '{}', 'x')],
            'transport not a code' => [
                static fn () => new TransportContext(new Caller(UserContextInterface::USER_TYPE_ADMIN, 1), 'HTTP'),
            ],
            'body without messages' => [static fn () => (new ParsedBody(true))->withMessages([])],
            'single body with two messages' => [
                static fn () => (new ParsedBody(false))->withMessages([
                    new Message(MessageKind::Request, 'ping', 1),
                    new Message(MessageKind::Request, 'ping', 2),
                ]),
            ],
        ];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('contradictions')]
    public function testAContradictoryValueCannotBeBuilt(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $build();
    }

    public function testWithParamsReturnsACopy(): void
    {
        $message = new Message(MessageKind::Request, 'tools/call', 'a');

        $withParams = $message->withParams(['name' => 'x']);

        self::assertSame([], $message->getParams());
        self::assertSame(['name' => 'x'], $withParams->getParams());
        self::assertSame('a', $withParams->getId());
    }

    public function testAResultReplyIsAnEmptyObjectUntilItGetsAResult(): void
    {
        $reply = new Reply();

        $withResult = $reply->withResult(['tools' => []]);

        self::assertFalse($reply->isError());
        self::assertEquals((object) [], $reply->getResult());
        self::assertSame(['tools' => []], $withResult->getResult());
    }

    public function testAnErrorReplyCarriesDataButNoResult(): void
    {
        $reply = new Reply(ErrorCode::AbilityDenied, 'Denied.');

        $withData = $reply->withErrorData(['ability' => 'a.b.c']);

        self::assertTrue($reply->isError());
        self::assertNull($reply->getErrorData());
        self::assertSame(['ability' => 'a.b.c'], $withData->getErrorData());
        $this->expectException(\LogicException::class);
        $reply->withResult([]);
    }

    public function testAResultReplyTakesNoErrorData(): void
    {
        $this->expectException(\LogicException::class);

        (new Reply())->withErrorData(['x' => 1]);
    }

    public function testATransportContextPassesOnWhatTheClientSentAsItIs(): void
    {
        $caller = new Caller(UserContextInterface::USER_TYPE_ADMIN, 1);

        $context = new TransportContext($caller, 'http', 'not even hex', 'any version');

        self::assertSame($caller, $context->getCaller());
        self::assertSame('http', $context->getTransport());
        self::assertSame('not even hex', $context->getSessionId());
        self::assertSame('any version', $context->getProtocolVersion());
    }

    public function testAnAnswerStatusThatAllowsABodyKeepsIt(): void
    {
        $answer = new ProtocolAnswer(AnswerStatus::Ok, '{"a":1}', self::SESSION);

        self::assertSame(AnswerStatus::Ok, $answer->getStatus());
        self::assertSame('{"a":1}', $answer->getBody());
        self::assertSame(self::SESSION, $answer->getSessionId());
        self::assertTrue(AnswerStatus::Forbidden->allowsBody());
        self::assertFalse(AnswerStatus::Accepted->allowsBody());
    }
}
