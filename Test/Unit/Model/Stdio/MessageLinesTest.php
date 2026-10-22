<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Protocol\JsonRpcResponses;
use Hryvinskyi\McpServer\Model\Stdio\MessageLines;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line read the way the protocol server reads it: the answer a failed line may get under JSON-RPC's rules, and
 * whether it opens or confirms a session
 */
class MessageLinesTest extends TestCase
{
    private const INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';

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
        return '{"jsonrpc":"2.0","id":' . $id . ',"error":{"code":-32603,"message":"Internal error"}}';
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
