<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Method;

use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeTool;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `tools/call` and `tools/list` through the server: routing by tool name, Invalid params, and tool errors
 */
class ToolsCallTest extends TestCase
{
    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
    }

    public function testTheCallReachesTheToolOfThatNameWithItsArguments(): void
    {
        $first = new FakeTool('first');
        $second = new FakeTool('second');

        $response = $this->call([$first, $second], ['name' => 'second', 'arguments' => ['a' => 1, '7' => 'seven']]);

        self::assertSame([], $first->calls);
        self::assertSame([['a' => 1, '7' => 'seven']], $second->calls);
        self::assertSame(
            ['content' => [], 'structuredContent' => ['a' => 1, '7' => 'seven'], 'isError' => false],
            $response['result']
        );
    }

    public function testACallWithoutArgumentsGivesTheToolNone(): void
    {
        $tool = new FakeTool();

        $this->call([$tool], ['name' => 'fake_tool']);

        self::assertSame([[]], $tool->calls);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidParams(): array
    {
        return [
            'unknown tool' => [['name' => 'execute_everything'], '"execute_everything" is not a tool'],
            'no name' => [['arguments' => []], 'is not a tool'],
            'name not a string' => [['name' => ['fake_tool']], 'is not a tool'],
            'arguments a list' => [['name' => 'fake_tool', 'arguments' => [1]], '"arguments"'],
            'arguments a string' => [['name' => 'fake_tool', 'arguments' => 'a=1'], '"arguments"'],
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @param string $text
     */
    #[DataProvider('invalidParams')]
    public function testARequestNamingNoToolOrWithoutObjectArgumentsIsInvalidParams(array $params, string $text): void
    {
        $tool = new FakeTool();

        $response = $this->call([$tool], $params);

        self::assertIsArray($response['error']);
        self::assertSame(-32602, $response['error']['code']);
        self::assertIsString($response['error']['message']);
        self::assertStringContainsString($text, $response['error']['message']);
        self::assertSame([], $tool->calls);
    }

    public function testAnUnknownToolNamesTheToolsThereAre(): void
    {
        $response = $this->call([new FakeTool('first'), new FakeTool('second')], ['name' => 'third']);

        self::assertIsArray($response['error']);
        self::assertIsString($response['error']['message']);
        self::assertStringContainsString('first, second', $response['error']['message']);
    }

    public function testAnArgumentTheToolRefusesIsAToolErrorTheModelReads(): void
    {
        $tool = new FakeTool('picky', static function (array $arguments, CallContext $context): array {
            throw new InvalidToolArgumentException('The argument "limit" must be an integer from 1 to 100.');
        });

        $response = $this->call([$tool], ['name' => 'picky']);

        self::assertSame(
            [
                'content' => [['type' => 'text', 'text' => 'The argument "limit" must be an integer from 1 to 100.']],
                'isError' => true,
            ],
            $response['result']
        );
    }

    public function testToolsListGivesEveryToolWithItsSchemaAndAnnotations(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->kit->server([new FakeTool('first'), new FakeTool('second')])->handle(
            '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            $this->kit->context($session->getId())
        );

        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{"tools":['
            . '{"name":"first","description":"A tool for tests.","inputSchema":{"type":"object","properties":{}},'
            . '"annotations":{"readOnlyHint":true}},'
            . '{"name":"second","description":"A tool for tests.","inputSchema":{"type":"object","properties":{}},'
            . '"annotations":{"readOnlyHint":true}}]}}',
            $answer->getBody()
        );
    }

    /**
     * Send `tools/call` with these params in a session and give the decoded response
     *
     * @param list<FakeTool> $tools
     * @param array<string,mixed> $params
     * @return array<mixed>
     */
    private function call(array $tools, array $params): array
    {
        $session = $this->kit->openSession();
        $answer = $this->kit->server($tools)->handle(
            json_encode(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params],
                JSON_THROW_ON_ERROR
            ),
            $this->kit->context($session->getId())
        );
        $response = json_decode($answer->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($response);

        return $response;
    }
}
