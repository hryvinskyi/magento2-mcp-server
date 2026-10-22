<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use Hryvinskyi\McpServer\Model\Protocol\JsonRpcResponses;
use Hryvinskyi\McpServer\Model\Stdio\Envelope;
use Hryvinskyi\McpServer\Model\Stdio\MessageLines;
use Hryvinskyi\McpServer\Model\Stdio\ProcessResult;
use Hryvinskyi\McpServer\Model\Stdio\ServedSession;
use Hryvinskyi\McpServer\Model\Stdio\ServedSessionFactory;
use Hryvinskyi\McpServer\Model\Stdio\StdioLoop;
use Hryvinskyi\McpServer\Model\Stdio\StdioStreams;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use Hryvinskyi\McpServer\Test\Unit\Fake\ScriptedProcessRunner;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The serving loop of the stdio transport over in-memory streams and a scripted process runner: one message process
 * per line, started from an argument list, nothing but answer bodies on the output, only the refusal code stops it,
 * and a lost session is opened again once per line
 */
class StdioLoopTest extends TestCase
{
    private const PHP = '/usr/bin/php';
    private const SESSION = 'abababababababababababababababab';
    private const OTHER_SESSION = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';
    private const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize"}';
    private const INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';
    private const LIST = '{"jsonrpc":"2.0","id":2,"method":"tools/list"}';
    private const REFUSED = 77;
    private const NOT_FOUND = '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Session not found."}}';
    private const BASE_COMMAND = [
        self::PHP,
        '-d',
        'display_errors=stderr',
        '/srv/shop/bin/magento',
        'hryvinskyi:mcp:stdio-message',
        '--admin-user=jane',
    ];

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $errorOutput;

    protected function setUp(): void
    {
        $this->output = $this->stream('');
        $this->errorOutput = $this->stream('');
    }

    public function testTheStartupCheckRunsFirstWithNoMessageAndItsRefusalEndsTheLoopWithExitOne(): void
    {
        $runner = new ScriptedProcessRunner([new ProcessResult(self::REFUSED, '', "The MCP server is disabled.\n")]);

        $exit = $this->loop($runner)
            ->run('jane', $this->stream(self::INITIALIZE . "\n"), $this->output, $this->errorOutput);

        self::assertSame(1, $exit);
        self::assertSame([['command' => self::BASE_COMMAND, 'input' => '', 'timeout' => 300]], $runner->runs);
        self::assertSame('', $this->contents($this->output));
        self::assertStringStartsWith("The MCP server is disabled.\n", $this->contents($this->errorOutput));
    }

    public function testAStartupProcessThatCrashesAlsoEndsTheLoopWithExitOne(): void
    {
        $runner = new ScriptedProcessRunner([new ProcessResult(255, '', 'PHP Fatal error')]);

        $exit = $this->loop($runner)
            ->run('jane', $this->stream(self::INITIALIZE . "\n"), $this->output, $this->errorOutput);

        self::assertSame(1, $exit);
        self::assertCount(1, $runner->runs);
        self::assertStringContainsString('exited with code 255', $this->contents($this->errorOutput));
    }

    public function testAStartupProcessThatAnswersInsteadOfPassingSilentlyAlsoEndsTheLoopWithExitOne(): void
    {
        $runner = new ScriptedProcessRunner([
            $this->envelope('{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}', ''),
        ]);

        $exit = $this->loop($runner)
            ->run('jane', $this->stream(self::INITIALIZE . "\n"), $this->output, $this->errorOutput);

        self::assertSame(1, $exit);
        self::assertCount(1, $runner->runs);
        self::assertSame('', $this->contents($this->output));
        self::assertStringContainsString('answered with an error', $this->contents($this->errorOutput));
    }

    public function testEachLineGoesToItsOwnProcessAndTheAnnouncedSessionIsUsedAndEndedAtTheEnd(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope('{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}', ''),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(0, $exit);
        self::assertSame(
            [
                ['command' => self::BASE_COMMAND, 'input' => '', 'timeout' => 300],
                ['command' => self::BASE_COMMAND, 'input' => self::INITIALIZE, 'timeout' => 300],
                [
                    'command' => [...self::BASE_COMMAND, '--session=' . self::SESSION],
                    'input' => self::LIST,
                    'timeout' => 300,
                ],
                [
                    'command' => [...self::BASE_COMMAND, '--session=' . self::SESSION, '--end-session'],
                    'input' => '',
                    'timeout' => 300,
                ],
            ],
            $runner->runs
        );
        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n" . '{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}' . "\n",
            $this->contents($this->output)
        );
    }

    public function testEveryCommandIsAListOfSeparateArgumentsAndALoginWithSpacesStaysOneArgument(): void
    {
        $runner = new ScriptedProcessRunner();

        $this->loop($runner)->run('jane doe; rm -rf /', $this->stream(''), $this->output, $this->errorOutput);

        self::assertSame(
            [...array_slice(self::BASE_COMMAND, 0, 5), '--admin-user=jane doe; rm -rf /'],
            $runner->runs[0]['command']
        );
    }

    public function testASessionIdThatIsNotOneIsIgnored(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', '../not-a-session'),
        ]);

        $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(self::BASE_COMMAND, $runner->runs[2]['command']);
        self::assertCount(3, $runner->runs, 'No session is known, so none is ended.');
    }

    public function testALaterSessionReplacesTheOneKnown(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::OTHER_SESSION),
        ]);

        $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::INITIALIZE . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(
            [...self::BASE_COMMAND, '--session=' . self::OTHER_SESSION, '--end-session'],
            $runner->runs[3]['command']
        );
    }

    public function testEmptyLinesAreSkipped(): void
    {
        $runner = new ScriptedProcessRunner([new ProcessResult(0), $this->envelope('', '')]);

        $this->loop($runner)->run(
            'jane',
            $this->stream("\n   \n\r\n" . self::LIST . "\n\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertCount(2, $runner->runs);
        self::assertSame(self::LIST, $runner->runs[1]['input']);
        self::assertSame('', $this->contents($this->output), 'An empty body is not written.');
    }

    public function testALineOverTheLimitIsAnsweredWithoutAProcessAndTheNextLineIsStillHandled(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":2,"result":{}}', ''),
        ]);

        $exit = $this->loop($runner, 64)->run(
            'jane',
            $this->stream(str_repeat('x', 200000) . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(0, $exit);
        self::assertCount(2, $runner->runs);
        self::assertSame(self::LIST, $runner->runs[1]['input']);
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"The message is too large."}}' . "\n"
            . '{"jsonrpc":"2.0","id":2,"result":{}}' . "\n",
            $this->contents($this->output)
        );
    }

    public function testALineOfExactlyTheLimitIsHandled(): void
    {
        $runner = new ScriptedProcessRunner([new ProcessResult(0), $this->envelope('', '')]);
        $line = str_pad('{"a":"', 63, 'x') . '"';

        $this->loop($runner, 64)->run('jane', $this->stream($line . "\n"), $this->output, $this->errorOutput);

        self::assertSame($line, $runner->runs[1]['input']);
    }

    public function testAProcessThatRefusesMidwayEndsTheLoopWithExitOneAndEndsNothing(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            new ProcessResult(self::REFUSED, '', "The admin user \"jane\" is inactive.\n"),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(1, $exit);
        self::assertCount(3, $runner->runs);
        self::assertSame("The admin user \"jane\" is inactive.\n", $this->contents($this->errorOutput));
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}' . "\n", $this->contents($this->output));
    }

    public function testACrashAnExitOneGarbageOrATimeoutFailsTheMessageWithItsIdAndTheLoopGoesOn(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            new ProcessResult(255, '', "PHP Fatal error: out of memory\n"),
            new ProcessResult(1, '', "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away\n"),
            new ProcessResult(0, "Deprecated: something\n"),
            new ProcessResult(null, '', '', true),
            $this->envelope('{"jsonrpc":"2.0","id":5,"result":{}}', ''),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(str_repeat(self::LIST . "\n", 5)),
            $this->output,
            $this->errorOutput
        );

        $internalError = '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"Internal error"}}' . "\n";
        self::assertSame(0, $exit);
        self::assertCount(6, $runner->runs);
        self::assertSame(
            str_repeat($internalError, 4) . '{"jsonrpc":"2.0","id":5,"result":{}}' . "\n",
            $this->contents($this->output)
        );
        $errors = $this->contents($this->errorOutput);
        self::assertStringContainsString("PHP Fatal error: out of memory\n", $errors);
        self::assertStringContainsString('exited with code 255', $errors);
        self::assertStringContainsString('MySQL server has gone away', $errors);
        self::assertStringContainsString('exited with code 1.', $errors);
        self::assertStringContainsString('wrote no valid answer envelope', $errors);
        self::assertStringContainsString('ran longer than 300 seconds', $errors);
        self::assertStringNotContainsString('Deprecated', $this->contents($this->output));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failedLines(): array
    {
        $error = static fn (string $id): string
            => '{"jsonrpc":"2.0","id":' . $id . ',"error":{"code":-32603,"message":"Internal error"}}';

        return [
            'a request with an integer id' => ['{"jsonrpc":"2.0","id":7,"method":"ping"}', $error('7') . "\n"],
            'a request with a string id' => ['{"jsonrpc":"2.0","id":"a-1","method":"ping"}', $error('"a-1"') . "\n"],
            'a notification' => [self::INITIALIZED, ''],
            'a client response' => ['{"jsonrpc":"2.0","id":3,"result":{}}', ''],
            'a batch' => [
                '[{"jsonrpc":"2.0","id":1,"method":"ping"},' . self::INITIALIZED
                . ',{"jsonrpc":"2.0","id":"b","method":"ping"}]',
                '[' . $error('1') . ',' . $error('"b"') . "]\n",
            ],
            'a batch of notifications' => ['[' . self::INITIALIZED . ',' . self::INITIALIZED . ']', ''],
            'not JSON' => ['{"jsonrpc":"2.0","id":4,', $error('null') . "\n"],
            'a request whose id is null' => ['{"jsonrpc":"2.0","id":null,"method":"ping"}', $error('null') . "\n"],
        ];
    }

    #[DataProvider('failedLines')]
    public function testAFailedMessageIsAnsweredTheWayJsonRpcAllows(string $line, string $answer): void
    {
        $runner = new ScriptedProcessRunner([new ProcessResult(0), new ProcessResult(255)]);

        $exit = $this->loop($runner)->run('jane', $this->stream($line . "\n"), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        self::assertSame($answer, $this->contents($this->output));
    }

    public function testALostSessionIsOpenedAgainTheWayTheClientOpenedItAndTheLineIsSentOnceMore(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope('', ''),
            $this->envelope(self::NOT_FOUND, '', true),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{"replayed":true}}', self::OTHER_SESSION),
            $this->envelope('', ''),
            $this->envelope('{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}', ''),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::INITIALIZED . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(0, $exit);
        self::assertSame(
            [
                ['command' => self::BASE_COMMAND, 'input' => ''],
                ['command' => self::BASE_COMMAND, 'input' => self::INITIALIZE],
                ['command' => [...self::BASE_COMMAND, '--session=' . self::SESSION], 'input' => self::INITIALIZED],
                ['command' => [...self::BASE_COMMAND, '--session=' . self::SESSION], 'input' => self::LIST],
                ['command' => self::BASE_COMMAND, 'input' => self::INITIALIZE],
                [
                    'command' => [...self::BASE_COMMAND, '--session=' . self::OTHER_SESSION],
                    'input' => self::INITIALIZED,
                ],
                ['command' => [...self::BASE_COMMAND, '--session=' . self::OTHER_SESSION], 'input' => self::LIST],
                [
                    'command' => [...self::BASE_COMMAND, '--session=' . self::OTHER_SESSION, '--end-session'],
                    'input' => '',
                ],
            ],
            $this->commandsAndInputs($runner)
        );
        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n" . '{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}' . "\n",
            $this->contents($this->output),
            'The answers of the replayed lines are dropped.'
        );
    }

    public function testWithoutTheNotificationOnlyInitializeIsReplayed(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::OTHER_SESSION),
            $this->envelope('{"jsonrpc":"2.0","id":2,"result":{}}', ''),
        ]);

        $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(
            [self::INITIALIZE, self::LIST, self::INITIALIZE, self::LIST, ''],
            array_column(array_slice($runner->runs, 1), 'input')
        );
        self::assertSame([...self::BASE_COMMAND, '--session=' . self::OTHER_SESSION], $runner->runs[4]['command']);
    }

    public function testALaterInitializeForgetsTheNotificationOfTheEarlierSession(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope('', ''),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::OTHER_SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope('{"jsonrpc":"2.0","id":2,"result":{}}', ''),
        ]);

        $this->loop($runner)->run(
            'jane',
            $this->stream(implode("\n", [self::INITIALIZE, self::INITIALIZED, self::INITIALIZE, self::LIST]) . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(
            [self::INITIALIZE, self::INITIALIZED, self::INITIALIZE, self::LIST, self::INITIALIZE, self::LIST, ''],
            array_column(array_slice($runner->runs, 1), 'input')
        );
    }

    public function testWhenTheSessionCannotBeOpenedAgainTheLostAnswerIsWrittenAndTheLoopGoesOn(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            new ProcessResult(255, '', "PHP Fatal error\n"),
            $this->envelope('{"jsonrpc":"2.0","id":3,"result":{}}', ''),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(
                implode("\n", [self::INITIALIZE, self::LIST, '{"jsonrpc":"2.0","id":3,"method":"ping"}']) . "\n"
            ),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(0, $exit);
        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n" . self::NOT_FOUND . "\n"
            . '{"jsonrpc":"2.0","id":3,"result":{}}' . "\n",
            $this->contents($this->output)
        );
        self::assertStringContainsString('could not be opened again', $this->contents($this->errorOutput));
        self::assertSame(self::INITIALIZE, $runner->runs[3]['input']);
        self::assertSame([...self::BASE_COMMAND, '--session=' . self::SESSION], $runner->runs[4]['command']);
    }

    public function testAReplayThatOpensNoSessionCountsAsAFailedReplay(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            $this->envelope('{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"No."}}', ''),
        ]);

        $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertCount(5, $runner->runs);
        self::assertStringEndsWith(self::NOT_FOUND . "\n", $this->contents($this->output));
    }

    public function testALineThatFindsTheNewSessionLostAsWellIsAnsweredAsLostWithoutAnotherReplay(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::OTHER_SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(0, $exit);
        self::assertSame(
            [self::INITIALIZE, self::LIST, self::INITIALIZE, self::LIST, ''],
            array_column(array_slice($runner->runs, 1), 'input'),
            'One replay for the line, then the end of the session the replay opened.'
        );
        self::assertSame(
            '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n" . self::NOT_FOUND . "\n",
            $this->contents($this->output)
        );
        self::assertStringContainsString('was lost as well', $this->contents($this->errorOutput));
    }

    public function testALostSessionThatNoInitializeOfThisRunOpenedIsAnsweredAsLost(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope(self::NOT_FOUND, '', true),
        ]);

        $this->loop($runner)->run('jane', $this->stream(self::LIST . "\n"), $this->output, $this->errorOutput);

        self::assertCount(2, $runner->runs);
        self::assertSame(self::NOT_FOUND . "\n", $this->contents($this->output));
    }

    public function testARetryThatFailsIsAnsweredWithTheLinesOwnInternalError(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::OTHER_SESSION),
            new ProcessResult(255),
        ]);

        $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertStringEndsWith(
            '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"Internal error"}}' . "\n",
            $this->contents($this->output)
        );
    }

    public function testARefusalWhileTheSessionIsOpenedAgainEndsTheLoopWithExitOne(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0),
            $this->envelope('{"jsonrpc":"2.0","id":1,"result":{}}', self::SESSION),
            $this->envelope(self::NOT_FOUND, '', true),
            new ProcessResult(self::REFUSED, '', "The MCP server is disabled.\n"),
        ]);

        $exit = $this->loop($runner)->run(
            'jane',
            $this->stream(self::INITIALIZE . "\n" . self::LIST . "\n" . self::LIST . "\n"),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(1, $exit);
        self::assertCount(4, $runner->runs);
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}' . "\n", $this->contents($this->output));
    }

    public function testTheProcessesErrorOutputIsAlwaysCopiedAndNeverReachesTheOutput(): void
    {
        $runner = new ScriptedProcessRunner([
            new ProcessResult(0, '', "startup notice\n"),
            new ProcessResult(0, (new Envelope(new JsonEncoder()))->encode('{"x":1}', ''), "message notice\n"),
        ]);

        $this->loop($runner)->run('jane', $this->stream(self::LIST . "\n"), $this->output, $this->errorOutput);

        self::assertSame("startup notice\nmessage notice\n", $this->contents($this->errorOutput));
        self::assertSame('{"x":1}' . "\n", $this->contents($this->output));
    }

    public function testAnAnswerBodyIsWrittenRawEvenWhenItLooksLikeConsoleMarkup(): void
    {
        $body = '{"jsonrpc":"2.0","id":2,"result":{"text":"<info>a\\\\<b</info>"}}';
        $runner = new ScriptedProcessRunner([new ProcessResult(0), $this->envelope($body, '')]);

        $this->loop($runner)->run('jane', $this->stream(self::LIST . "\n"), $this->output, $this->errorOutput);

        self::assertSame($body . "\n", $this->contents($this->output));
    }

    public function testTheTimeLimitAndTheLineLimitMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->loop(new ScriptedProcessRunner(), 0);
    }

    /**
     * @param ScriptedProcessRunner $runner
     * @param int $maxLineBytes
     * @return StdioLoop
     */
    private function loop(ScriptedProcessRunner $runner, int $maxLineBytes = 1048576): StdioLoop
    {
        $kit = new ProtocolKit($this);

        return new StdioLoop(
            $runner,
            new StdioStreams(new File()),
            new Envelope(new JsonEncoder()),
            $kit->answers(),
            new MessageLines($kit->parser(), new JsonRpcResponses(), $kit->answers()),
            new ServedSessionFactory(new ConstructingObjectManager([ServedSession::class => ServedSession::class])),
            new DirectoryList('/srv/shop'),
            300,
            $maxLineBytes,
            self::PHP
        );
    }

    /**
     * A message process that answered with this envelope
     *
     * @param string $body
     * @param string $sessionId
     * @param bool $sessionLost
     * @return ProcessResult
     */
    private function envelope(string $body, string $sessionId, bool $sessionLost = false): ProcessResult
    {
        return new ProcessResult(0, (new Envelope(new JsonEncoder()))->encode($body, $sessionId, $sessionLost));
    }

    /**
     * The command and input of every run, without the time limit
     *
     * @param ScriptedProcessRunner $runner
     * @return list<array{command: list<string>, input: string}>
     */
    private function commandsAndInputs(ScriptedProcessRunner $runner): array
    {
        return array_map(
            static fn (array $run): array => ['command' => $run['command'], 'input' => $run['input']],
            $runner->runs
        );
    }

    /**
     * An in-memory stream holding this text, read from the start
     *
     * @param string $text
     * @return resource
     */
    private function stream(string $text)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }

    /**
     * Everything written to the stream
     *
     * @param resource $stream
     * @return string
     */
    private function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
