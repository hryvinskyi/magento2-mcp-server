<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Hryvinskyi\McpServer\Model\Protocol\TransportContextFactory;
use Hryvinskyi\McpServer\Model\Stdio\AdminAccount;
use Hryvinskyi\McpServer\Model\Stdio\AdminAccountsInterface;
use Hryvinskyi\McpServer\Model\Stdio\CallerAdmission;
use Hryvinskyi\McpServer\Model\Stdio\CliAdminUserContext;
use Hryvinskyi\McpServer\Model\Stdio\Envelope;
use Hryvinskyi\McpServer\Model\Stdio\MessageHandler;
use Hryvinskyi\McpServer\Model\Stdio\SapiName;
use Hryvinskyi\McpServer\Model\Stdio\StdioStreams;
use Hryvinskyi\McpServer\Model\Transport\ChannelSecurity;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAdminAccounts;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProbingAuthorization;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One stdio message as its process handles it: the caller checks in their fixed order, then the message, the end of
 * a session or nothing, answered as one envelope line
 */
class MessageHandlerTest extends TestCase
{
    private const ACCESS = 'Hryvinskyi_McpServer::access';
    private const ADMIN_ID = 7;
    private const SESSION = 'abababababababababababababababab';
    private const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18",'
        . '"clientInfo":{"name":"t","version":"1"}}}';

    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    /**
     * @var CliAdminUserContext
     */
    private CliAdminUserContext $cliContext;

    /**
     * @var ProbingAuthorization
     */
    private ProbingAuthorization $authorization;

    /**
     * @var ChannelSecurity
     */
    private ChannelSecurity $channel;

    /**
     * Store codes the handler made current, in order
     *
     * @var list<string>
     */
    private array $currentStores = [];

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
        $this->kit = new ProtocolKit($this);
        $this->cliContext = new CliAdminUserContext(new SapiName('cli'));
        $this->authorization = new ProbingAuthorization($this->cliContext, [self::ACCESS]);
        $request = $this->createStub(HttpRequest::class);
        $request->method('isSecure')->willReturn(false);
        $this->channel = new ChannelSecurity($request);
        $this->output = $this->stream('');
        $this->errorOutput = $this->stream('');
    }

    public function testInitializeIsAnsweredAsOneEnvelopeLineThatAnnouncesTheNewSession(): void
    {
        $exit = $this->handler()
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        $line = $this->contents($this->output);
        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, substr_count($line, "\n"));
        $envelope = (new Envelope(new JsonEncoder()))->decode($line);
        self::assertNotNull($envelope);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $envelope['sessionId']);
        self::assertStringStartsWith('{"jsonrpc":"2.0","id":1,"result":{', $envelope['body']);
        $session = $this->kit->sessions->find($envelope['sessionId']);
        self::assertNotNull($session);
        self::assertSame(UserContextInterface::USER_TYPE_ADMIN, $session->getCaller()->getUserType());
        self::assertSame(self::ADMIN_ID, $session->getCaller()->getUserId());
        self::assertSame('', $this->contents($this->errorOutput));
        self::assertSame(['admin'], $this->currentStores);
        self::assertTrue($this->channel->isSecure(), 'The channel is marked a local pipe once the caller is admitted.');
    }

    public function testTheAclIsAskedOnlyOnceTheAdminIsSetOnTheUserContext(): void
    {
        $this->handler()->handle('jane', '', false, $this->stream(''), $this->output, $this->errorOutput);

        self::assertSame(
            [['resource' => self::ACCESS, 'userType' => UserContextInterface::USER_TYPE_ADMIN, 'userId' => 7]],
            $this->authorization->asked
        );
    }

    public function testAMessageInTheKnownSessionIsHandledInIt(): void
    {
        $this->openSession();

        $this->handler()->handle(
            'jane',
            self::SESSION,
            false,
            $this->stream('{"jsonrpc":"2.0","id":2,"method":"ping"}'),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(
            '{"body":"{\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{}}","sessionId":"","sessionLost":false}' . "\n",
            $this->contents($this->output)
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedAccounts(): array
    {
        return [
            'unknown' => ['nobody', 'There is no admin user with the login "nobody".'],
            'inactive' => ['off', 'The admin user "off" is inactive.'],
            'locked' => ['locked', 'The admin user "locked" is locked.'],
            'expired' => ['expired', 'The admin user "expired" has expired.'],
        ];
    }

    #[DataProvider('refusedAccounts')]
    public function testAnAccountThatCannotActIsRefusedBeforeAnythingElse(string $login, string $message): void
    {
        $exit = $this->handler()
            ->handle($login, '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        $this->assertRefused($exit, $message);
        self::assertSame([], $this->authorization->asked);
        self::assertNull($this->cliContext->getUserId());
        self::assertSame([], $this->kit->sessions->sessions);
    }

    public function testAUserContextThatDoesNotAnswerTheAdminIsRefusedBeforeTheAcl(): void
    {
        $guest = $this->createStub(UserContextInterface::class);
        $guest->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_GUEST);
        $guest->method('getUserId')->willReturn(0);

        $exit = $this->handler(userContext: $guest)
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        $this->assertRefused($exit, 'The webapi_rest user context could not be loaded in this process.');
        self::assertSame([], $this->authorization->asked);
    }

    public function testARoleWithoutAccessIsRefused(): void
    {
        $this->authorization = new ProbingAuthorization($this->cliContext, []);

        $exit = $this->handler()
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        $this->assertRefused(
            $exit,
            'The role of the admin user "jane" does not allow "Use the MCP endpoint" (' . self::ACCESS . ').'
        );
        self::assertCount(1, $this->authorization->asked);
        self::assertFalse($this->channel->isSecure());
    }

    public function testADisabledServerIsRefusedAfterTheAcl(): void
    {
        $exit = $this->handler(enabled: false)
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        $this->assertRefused($exit, 'The MCP server is disabled.');
        self::assertCount(1, $this->authorization->asked);
    }

    public function testAnEmptyInputIsTheStartupCheckAndWritesNothing(): void
    {
        $exit = $this->handler()
            ->handle('jane', '', false, $this->stream(''), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        self::assertSame('', $this->contents($this->output));
        self::assertSame('', $this->contents($this->errorOutput));
    }

    public function testEndSessionEndsTheSessionAndWritesNothing(): void
    {
        $this->openSession();

        $exit = $this->handler()
            ->handle('jane', self::SESSION, true, $this->stream(''), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        self::assertSame([self::SESSION], $this->kit->sessions->deleted);
        self::assertSame('', $this->contents($this->output));
    }

    public function testAMessageOverTheLimitIsAnsweredWithAnInvalidRequestError(): void
    {
        $exit = $this->handler(maxMessageBytes: 16)
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        self::assertSame(
            '{"body":"{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32600,'
            . '\"message\":\"The message is too large.\"}}","sessionId":"","sessionLost":false}' . "\n",
            $this->contents($this->output)
        );
        self::assertSame([], $this->kit->sessions->sessions);
    }

    public function testAFailureAfterTheChecksIsLoggedAndAnsweredWithAnInternalError(): void
    {
        $exit = $this->handler(contexts: new TransportContextFactory(new ConstructingObjectManager([])))
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        self::assertSame(
            '{"body":"{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32603,'
            . '\"message\":\"Internal error\"}}","sessionId":"","sessionLost":false}' . "\n",
            $this->contents($this->output)
        );
        self::assertCount(1, $this->kit->logger->messages('error'));
    }

    public function testAMessageInASessionTheServerNoLongerKnowsReportsTheSessionLost(): void
    {
        $exit = $this->handler()->handle(
            'jane',
            self::SESSION,
            false,
            $this->stream('{"jsonrpc":"2.0","id":2,"method":"ping"}'),
            $this->output,
            $this->errorOutput
        );

        self::assertSame(0, $exit);
        $envelope = (new Envelope(new JsonEncoder()))->decode($this->contents($this->output));
        self::assertNotNull($envelope);
        self::assertTrue($envelope['sessionLost']);
        self::assertStringContainsString('"code":-32600', $envelope['body']);
    }

    public function testAMessageWithoutASessionIsAnsweredButNeverReportsASessionLost(): void
    {
        $this->handler()->handle(
            'jane',
            '',
            false,
            $this->stream('{"jsonrpc":"2.0","id":2,"method":"ping"}'),
            $this->output,
            $this->errorOutput
        );

        $envelope = (new Envelope(new JsonEncoder()))->decode($this->contents($this->output));
        self::assertNotNull($envelope);
        self::assertFalse($envelope['sessionLost']);
        self::assertNotSame('', $envelope['body']);
    }

    public function testAFailureWhileAdmittingTheCallerIsLoggedAndAnsweredInsteadOfEscaping(): void
    {
        $accounts = $this->createStub(AdminAccountsInterface::class);
        $accounts->method('byLogin')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')
        );

        $exit = $this->handler(accounts: $accounts)
            ->handle('jane', '', false, $this->stream(self::INITIALIZE), $this->output, $this->errorOutput);

        self::assertSame(0, $exit);
        self::assertSame(
            '{"body":"{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32603,'
            . '\"message\":\"Internal error\"}}","sessionId":"","sessionLost":false}' . "\n",
            $this->contents($this->output)
        );
        self::assertSame('', $this->contents($this->errorOutput));
        $errors = $this->kit->logger->messages('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('MySQL server has gone away', $errors[0]);
        self::assertSame([], $this->kit->sessions->sessions);
        self::assertFalse($this->channel->isSecure());
    }

    /**
     * Open a session of the admin with id 7, as an earlier message process would have
     *
     * @return void
     */
    private function openSession(): void
    {
        $this->kit->sessions->add(
            self::SESSION,
            new Caller(UserContextInterface::USER_TYPE_ADMIN, self::ADMIN_ID),
            new Client('t', '1')
        );
    }

    /**
     * @param int $exit
     * @param string $message
     * @return void
     */
    private function assertRefused(int $exit, string $message): void
    {
        self::assertSame(77, $exit);
        self::assertSame($message . "\n", $this->contents($this->errorOutput));
        self::assertSame('', $this->contents($this->output));
    }

    /**
     * @param UserContextInterface|null $userContext What Magento's user context answers; the CLI admin when null
     * @param bool $enabled
     * @param int $maxMessageBytes
     * @param TransportContextFactory|null $contexts
     * @param AdminAccountsInterface|null $accounts The admin accounts; jane, off, locked and expired when null
     * @return MessageHandler
     */
    private function handler(
        ?UserContextInterface $userContext = null,
        bool $enabled = true,
        int $maxMessageBytes = 1048576,
        ?TransportContextFactory $contexts = null,
        ?AdminAccountsInterface $accounts = null
    ): MessageHandler {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('setCurrentStore')->willReturnCallback(function ($store): void {
            $this->currentStores[] = is_string($store) ? $store : '?';
        });

        return new MessageHandler(
            $storeManager,
            new CallerAdmission(
                $accounts ?? new FakeAdminAccounts([
                    'jane' => new AdminAccount(self::ADMIN_ID, true, false),
                    'off' => new AdminAccount(8, false, false),
                    'locked' => new AdminAccount(9, true, true),
                    'expired' => new AdminAccount(10, true, false, true),
                ]),
                $this->cliContext,
                $userContext ?? $this->cliContext,
                $this->authorization,
                new FakeConfig($enabled),
                new CallerInterfaceFactory(new ConstructingObjectManager([CallerInterface::class => Caller::class]))
            ),
            $this->channel,
            $this->kit->server(),
            $contexts ?? new TransportContextFactory(
                new ConstructingObjectManager([TransportContext::class => TransportContext::class])
            ),
            $this->kit->answers(),
            new StdioStreams(new File()),
            new Envelope(new JsonEncoder()),
            $this->kit->logger,
            $maxMessageBytes
        );
    }

    /**
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
