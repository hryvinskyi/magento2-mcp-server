<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\Confirmation\ArgumentsFingerprint;
use Hryvinskyi\McpServer\Model\Confirmation\Confirmation;
use Hryvinskyi\McpServer\Model\Data\Caller;
use Hryvinskyi\McpServer\Model\Data\Client;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;
use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ExecuteAbility;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeInvoker;
use Hryvinskyi\McpServer\Model\Session\Session;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `execute_ability` against a fake invoker: the invocation it builds, how every outcome is answered, and how a call is
 * confirmed only by a token bound to exactly that call
 */
class ExecuteAbilityTest extends TestCase
{
    private const NAME = 'catalog.product_repository.get_list';

    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
    }

    public function testTheInvocationRunsAsTheCallerForTheSessionsClientWithTheArguments(): void
    {
        $invoker = $this->invoker($this->invocationResult(InvocationOutcome::Succeeded)->withValue(['items' => []]));
        $session = $this->kit->openSession();

        $this->tool($invoker)->call(
            ['name' => self::NAME, 'arguments' => ['searchCriteria' => ['pageSize' => 2]]],
            $this->kit->callContext($session)
        );

        self::assertCount(1, $invoker->invocations);
        $invocation = $invoker->invocations[0];
        self::assertSame(self::NAME, $invocation->getAbilityName());
        self::assertSame(['searchCriteria' => ['pageSize' => 2]], $invocation->getArguments());
        self::assertFalse($invocation->isConfirmed());
        self::assertSame(1, $invocation->getCaller()->getUserId());
        self::assertSame('Test Client', $invocation->getClient()->getName());
        self::assertSame('2.0', $invocation->getClient()->getVersion());
        self::assertSame('http', $invocation->getTransport());
        self::assertSame($session->getId(), $invocation->getSessionId());
    }

    public function testWithoutASessionTheClientIsAnonymousAndTheCallIsUnconfirmed(): void
    {
        $invoker = $this->invoker($this->invocationResult(InvocationOutcome::Succeeded));

        $this->tool($invoker)->call(['name' => self::NAME], $this->kit->callContext());

        $invocation = $invoker->invocations[0];
        self::assertSame('', $invocation->getClient()->getName());
        self::assertSame('', $invocation->getSessionId());
        self::assertFalse($invocation->isConfirmed());
        self::assertSame([], $invocation->getArguments());
    }

    /**
     * @return array<string, array{mixed, array<string, mixed>}>
     */
    public static function values(): array
    {
        return [
            'an object' => [['sku' => 'A1', 'qty' => 2.0], ['sku' => 'A1', 'qty' => 2.0]],
            'a list' => [[['id' => 'US'], ['id' => 'FR']], ['result' => [['id' => 'US'], ['id' => 'FR']]]],
            'a scalar' => [true, ['result' => true]],
            'null' => [null, ['result' => null]],
            'an empty array' => [[], ['result' => []]],
        ];
    }

    /**
     * @param mixed $value
     * @param array<string,mixed> $structured
     */
    #[DataProvider('values')]
    public function testASucceededCallCarriesItsValueAsTextAndAsAnObject(mixed $value, array $structured): void
    {
        $invoker = $this->invoker($this->invocationResult(InvocationOutcome::Succeeded)->withValue($value));

        $result = $this->tool($invoker)->call(['name' => self::NAME], $this->kit->callContext());

        self::assertFalse($result['isError']);
        self::assertSame($structured, $result['structuredContent']);
        self::assertSame(
            [['type' => 'text', 'text' => json_encode($structured, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]],
            $result['content']
        );
    }

    public function testAFailedCallIsAToolErrorWithTheInvokersMessage(): void
    {
        $invoker = $this->invoker(
            $this->invocationResult(InvocationOutcome::Failed, 'The product that was requested doesn\'t exist.')
        );

        $result = $this->tool($invoker)->call(['name' => self::NAME], $this->kit->callContext());

        self::assertTrue($result['isError']);
        self::assertSame(
            [['type' => 'text', 'text' => 'The product that was requested doesn\'t exist.']],
            $result['content']
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function denialReasons(): array
    {
        return [
            'deny-list' => [InvocationResultInterface::DENIAL_DENY_LIST],
            'read-only mode' => [InvocationResultInterface::DENIAL_READ_ONLY_MODE],
            'ACL' => [InvocationResultInterface::DENIAL_ACL],
        ];
    }

    #[DataProvider('denialReasons')]
    public function testADeniedCallIsAJsonRpcErrorNamingTheAbilityAndTheReason(string $reason): void
    {
        $invoker = $this->invoker($this->invocationResult(InvocationOutcome::Denied, 'Refused.', $reason));

        try {
            $this->tool($invoker)->call(['name' => self::NAME], $this->kit->callContext());
            self::fail('A denial must be a JSON-RPC error.');
        } catch (JsonRpcErrorException $exception) {
            self::assertSame(ErrorCode::AbilityDenied, $exception->getErrorCode());
            self::assertSame(-32003, $exception->getErrorCode()->value);
            self::assertSame('Refused.', $exception->getMessage());
            self::assertSame(['ability' => self::NAME, 'reason' => $reason], $exception->getData());
        }
    }

    public function testAnUnconfirmedCallIsAJsonRpcErrorCarryingATokenBoundToThatCall(): void
    {
        $session = $this->kit->openSession();
        $arguments = ['searchCriteria' => ['pageSize' => 2]];

        $exception = $this->confirmationError(
            $this->tool($this->confirmingInvoker()),
            ['name' => self::NAME, 'arguments' => $arguments],
            $session
        );

        self::assertSame(-32004, $exception->getErrorCode()->value);
        self::assertCount(1, $this->kit->confirmations->issued);
        $issued = $this->kit->confirmations->issued[0];
        self::assertSame(['ability' => self::NAME, 'confirmationToken' => $issued->getToken()], $exception->getData());
        self::assertStringContainsString(
            '"confirmationToken": "' . $issued->getToken() . '"',
            $exception->getMessage()
        );
        self::assertSame($session->getId(), $issued->getSessionId());
        self::assertSame(1, $issued->getCaller()->getUserId());
        self::assertSame(self::NAME, $issued->getAbility());
        self::assertSame(
            (new ArgumentsFingerprint(new JsonEncoder()))->of($arguments),
            $issued->getArgumentsFingerprint()
        );
    }

    public function testTheTokenConfirmsTheSameCallOnceAndIsSpentBeforeTheAbilityRuns(): void
    {
        $session = $this->kit->openSession();
        $invoker = $this->confirmingInvoker();
        $tool = $this->tool($invoker);
        $call = ['name' => self::NAME, 'arguments' => ['b' => 2, 'a' => ['y' => 1, 'x' => 0]]];
        $token = $this->tokenOf($this->confirmationError($tool, $call, $session));

        $result = $tool->call(
            [
                'name' => self::NAME,
                'arguments' => ['a' => ['x' => 0, 'y' => 1], 'b' => 2],
                'confirmationToken' => $token,
            ],
            $this->kit->callContext($session)
        );

        self::assertFalse($result['isError']);
        self::assertTrue($invoker->invocations[1]->isConfirmed());
        self::assertSame([], $this->kit->confirmations->tokens, 'The token is spent.');

        $again = $this->confirmationError($tool, $call + ['confirmationToken' => $token], $session);
        self::assertNotSame($token, $this->tokenOf($again), 'A spent token gets a fresh one.');
        self::assertFalse($invoker->invocations[2]->isConfirmed());
    }

    /**
     * @return array<string, array{string, array<string, mixed>, CallerInterface}>
     */
    public static function mismatchedCalls(): array
    {
        $session = str_repeat('ab', 16);
        $admin = new Caller(UserContextInterface::USER_TYPE_ADMIN, 1);

        return [
            'another session' => [str_repeat('ef', 16), ['a' => 1], $admin],
            'another admin' => [$session, ['a' => 1], new Caller(UserContextInterface::USER_TYPE_ADMIN, 2)],
            'another user type' => [$session, ['a' => 1], new Caller(UserContextInterface::USER_TYPE_INTEGRATION, 1)],
            'other arguments' => [$session, ['a' => 2], $admin],
            'an extra argument' => [$session, ['a' => 1, 'b' => 1], $admin],
        ];
    }

    /**
     * @param string $sessionId
     * @param array<string, mixed> $arguments
     * @param CallerInterface $caller
     */
    #[DataProvider('mismatchedCalls')]
    public function testATokenBoundToAnotherCallConfirmsNothingAndAFreshOneIsHandedOut(
        string $sessionId,
        array $arguments,
        CallerInterface $caller
    ): void {
        $session = $this->kit->openSession();
        $invoker = $this->confirmingInvoker();
        $tool = $this->tool($invoker);
        $token = $this->tokenOf(
            $this->confirmationError($tool, ['name' => self::NAME, 'arguments' => ['a' => 1]], $session)
        );
        $this->kit->sessions->add($sessionId, $caller, new Client('Test Client', '2.0'));

        try {
            $tool->call(
                ['name' => self::NAME, 'arguments' => $arguments, 'confirmationToken' => $token],
                new CallContext($this->kit->context($sessionId, '', $caller), $this->kit->sessions->find($sessionId))
            );
            self::fail('The call must still need a confirmation.');
        } catch (JsonRpcErrorException $exception) {
            self::assertSame(ErrorCode::ConfirmationRequired, $exception->getErrorCode());
            self::assertNotSame($token, $this->tokenOf($exception));
        }
        self::assertFalse($invoker->invocations[1]->isConfirmed());
        self::assertArrayNotHasKey($token, $this->kit->confirmations->tokens, 'A presented token is spent.');
    }

    public function testATokenForAnotherAbilityConfirmsNothing(): void
    {
        $session = $this->kit->openSession();
        $invoker = $this->confirmingInvoker();
        $tool = $this->tool($invoker);
        $token = $this->tokenOf($this->confirmationError($tool, ['name' => self::NAME], $session));

        $this->confirmationError(
            $tool,
            ['name' => 'catalog.product_repository.save', 'confirmationToken' => $token],
            $session
        );

        self::assertFalse($invoker->invocations[1]->isConfirmed());
    }

    public function testAnExpiredTokenConfirmsNothing(): void
    {
        $session = $this->kit->openSession();
        $invoker = $this->confirmingInvoker();
        $tool = $this->tool($invoker);
        $token = $this->tokenOf($this->confirmationError($tool, ['name' => self::NAME], $session));
        $this->kit->confirmations->now += Confirmation::LIFETIME_SECONDS;

        $this->confirmationError($tool, ['name' => self::NAME, 'confirmationToken' => $token], $session);

        self::assertFalse($invoker->invocations[1]->isConfirmed());
    }

    public function testAnUnknownTokenConfirmsNothing(): void
    {
        $session = $this->kit->openSession();
        $invoker = $this->confirmingInvoker();

        $this->confirmationError(
            $this->tool($invoker),
            ['name' => self::NAME, 'confirmationToken' => str_repeat('9', 32)],
            $session
        );

        self::assertFalse($invoker->invocations[0]->isConfirmed());
        self::assertSame([str_repeat('9', 32)], $this->kit->confirmations->presented);
    }

    public function testTheFormerConfirmFlagIsAToolErrorExplainingTheTokenAndNothingRuns(): void
    {
        $invoker = $this->confirmingInvoker();

        try {
            $this->tool($invoker)->call(['name' => self::NAME, 'confirm' => true], $this->kit->callContext());
            self::fail('The confirm flag must be refused.');
        } catch (InvalidToolArgumentException $exception) {
            self::assertStringContainsString('"confirm"', $exception->getMessage());
            self::assertStringContainsString('confirmationToken', $exception->getMessage());
        }
        self::assertSame([], $invoker->invocations);
        self::assertSame([], $this->kit->confirmations->presented);
    }

    public function testTheInputSchemaTakesAConfirmationTokenAndNoConfirmFlag(): void
    {
        $schema = $this->tool($this->confirmingInvoker())->getInputSchema();

        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('confirmationToken', $schema['properties']);
        self::assertSame('string', $this->arrayAt($schema['properties'], 'confirmationToken')['type']);
        self::assertArrayNotHasKey('confirm', $schema['properties']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testAnUnknownAbilityIsAToolError(): void
    {
        $invoker = new FakeInvoker(static function (InvocationInterface $invocation): InvocationResultInterface {
            throw new NoSuchEntityException(new Phrase('No ability "%1".', [$invocation->getAbilityName()]));
        });

        $result = $this->tool($invoker)->call(['name' => 'catalog.nothing.here'], $this->kit->callContext());

        self::assertTrue($result['isError']);
        self::assertIsArray($result['content']);
        self::assertStringContainsString('discover_abilities', (string) json_encode($result['content']));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): array
    {
        return [
            'no name' => [[], 'name'],
            'arguments a list' => [['name' => self::NAME, 'arguments' => [1, 2]], 'arguments'],
            'arguments a string' => [['name' => self::NAME, 'arguments' => '{"a":1}'], 'arguments'],
            'token a number' => [['name' => self::NAME, 'confirmationToken' => 7], 'confirmationToken'],
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @param string $named
     */
    #[DataProvider('refusedArguments')]
    public function testArgumentsItCannotTakeAreRefusedBeforeAnyInvocation(array $arguments, string $named): void
    {
        $invoker = $this->invoker($this->invocationResult(InvocationOutcome::Succeeded));

        try {
            $this->tool($invoker)->call($arguments, $this->kit->callContext());
            self::fail('The arguments must be refused.');
        } catch (InvalidToolArgumentException $exception) {
            self::assertStringContainsString('"' . $named . '"', $exception->getMessage());
        }
        self::assertSame([], $invoker->invocations);
    }

    public function testAnOutcomeWithoutARegisteredAnswerFailsLoudly(): void
    {
        $invoker = $this->invoker($this->invocationResult(InvocationOutcome::Succeeded));
        $tool = new ExecuteAbility(
            $invoker,
            $this->kit->invocationFactory(),
            $this->kit->clientFactory(),
            $this->kit->argumentReader(),
            $this->kit->toolResults(),
            $this->kit->confirmations,
            []
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('succeeded');

        $tool->call(['name' => self::NAME], $this->kit->callContext());
    }

    /**
     * @param FakeInvoker $invoker
     * @return ExecuteAbility
     */
    private function tool(FakeInvoker $invoker): ExecuteAbility
    {
        return $this->kit->executeAbility($invoker);
    }

    /**
     * An invoker that runs a confirmed call and asks a confirmation for any other
     *
     * @return FakeInvoker
     */
    private function confirmingInvoker(): FakeInvoker
    {
        return new FakeInvoker(
            fn (InvocationInterface $invocation): InvocationResultInterface => $invocation->isConfirmed()
                ? $this->invocationResult(InvocationOutcome::Succeeded)->withValue(['ran' => true])
                : $this->invocationResult(InvocationOutcome::NeedsConfirmation, 'Needs confirming.')
        );
    }

    /**
     * Call the tool in the session and return the confirmation error it must answer with
     *
     * @param ExecuteAbility $tool
     * @param array<string, mixed> $arguments
     * @param Session $session
     * @return JsonRpcErrorException
     */
    private function confirmationError(ExecuteAbility $tool, array $arguments, Session $session): JsonRpcErrorException
    {
        try {
            $tool->call($arguments, $this->kit->callContext($session));
        } catch (JsonRpcErrorException $exception) {
            self::assertSame(ErrorCode::ConfirmationRequired, $exception->getErrorCode());

            return $exception;
        }
        self::fail('The call must need a confirmation.');
    }

    /**
     * The confirmation token a confirmation error carries
     *
     * @param JsonRpcErrorException $exception
     * @return string
     */
    private function tokenOf(JsonRpcErrorException $exception): string
    {
        $token = $exception->getData()['confirmationToken'] ?? null;
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        return $token;
    }

    /**
     * @param array<mixed> $array
     * @param string $key
     * @return array<mixed>
     */
    private function arrayAt(array $array, string $key): array
    {
        $value = $array[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @param InvocationResultInterface $result
     * @return FakeInvoker
     */
    private function invoker(InvocationResultInterface $result): FakeInvoker
    {
        return new FakeInvoker(static fn (InvocationInterface $invocation): InvocationResultInterface => $result);
    }

    /**
     * @param InvocationOutcome $outcome
     * @param string $message
     * @param string $reason
     * @return InvocationResultInterface
     */
    private function invocationResult(
        InvocationOutcome $outcome,
        string $message = '',
        string $reason = ''
    ): InvocationResultInterface {
        return new InvocationResult($outcome, 1.0, null, $message, $reason);
    }
}
