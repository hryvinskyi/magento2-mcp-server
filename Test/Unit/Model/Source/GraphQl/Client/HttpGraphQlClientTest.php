<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Client;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\HttpGraphQlClient;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeEndpoint;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingCurlClient;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\HTTP\ClientFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The request the client builds, its limits and hardening, and how each answer or failure comes back
 */
class HttpGraphQlClientTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * Clients the factory handed out
     *
     * @var int
     */
    private int $created = 0;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->created = 0;
    }

    public function testPostsTheDocumentAndVariablesAsJsonToTheEndpointAsAGuestOfTheStoreView(): void
    {
        $curl = new RecordingCurlClient(200, '{"data":{"countries":[]}}');
        $client = $this->client($curl, new FakeEndpoint('https://shop.test/graphql', 'trade'));

        $answer = $client->request('query($id: String) { country(id: $id) { id } }', ['id' => 'UA', 'path' => 'a/é']);

        self::assertSame(['data' => ['countries' => []]], $answer);
        self::assertCount(1, $curl->requests);
        self::assertSame('POST', $curl->requests[0]['method']);
        self::assertSame('https://shop.test/graphql', $curl->requests[0]['uri']);
        self::assertSame(
            '{"query":"query($id: String) { country(id: $id) { id } }","variables":{"id":"UA","path":"a/é"}}',
            $curl->requests[0]['body']
        );
        self::assertSame(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Store' => 'trade', 'Expect' => ''],
            $curl->headers
        );
        self::assertSame([], $curl->cookies);
        self::assertFalse($curl->credentialsSet);
    }

    public function testNoVariablesAreSentAsAnEmptyObject(): void
    {
        $curl = new RecordingCurlClient(200, '{"data":{"__typename":"Query"}}');

        $this->client($curl)->request('{ __typename }', []);

        self::assertSame('{"query":"{ __typename }","variables":{}}', $curl->requests[0]['body']);
    }

    public function testEveryRequestGetsAFreshClientWithTimeoutsLimitsAndHardening(): void
    {
        $first = new RecordingCurlClient();
        $second = new RecordingCurlClient();
        $factory = $this->createStub(ClientFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($first, $second);
        $client = new HttpGraphQlClient($factory, new FakeEndpoint(), $this->logger);

        $client->request('{ a }', []);
        $client->request('{ b }', []);

        self::assertCount(1, $first->requests);
        self::assertCount(1, $second->requests);
        self::assertSame(30, $first->timeout);
        self::assertSame(5, $first->options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $first->options[CURLOPT_PROTOCOLS]);
        self::assertFalse($first->options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $first->options[CURLOPT_MAXREDIRS]);
        self::assertSame(5 * 1024 * 1024, $first->options[CURLOPT_MAXFILESIZE]);
        self::assertFalse($first->options[CURLOPT_NOPROGRESS]);
        self::assertIsCallable($first->options[CURLOPT_PROGRESSFUNCTION]);
        foreach ([CURLOPT_SSL_VERIFYPEER, CURLOPT_SSL_VERIFYHOST, CURLOPT_HTTPAUTH, CURLOPT_USERPWD, CURLOPT_COOKIE,
                     CURLOPT_COOKIEFILE, CURLOPT_COOKIEJAR, CURLOPT_HTTPHEADER] as $untouched) {
            self::assertArrayNotHasKey($untouched, $first->options);
        }
    }

    public function testTheProgressCallbackAbortsATransferPastFiveMegabytes(): void
    {
        $curl = new RecordingCurlClient();
        $this->client($curl)->request('{ a }', []);
        $progress = $curl->options[CURLOPT_PROGRESSFUNCTION];
        self::assertIsCallable($progress);
        $limit = 5 * 1024 * 1024;

        self::assertSame(0, $progress(null, 0, 0, 100, 100));
        self::assertSame(0, $progress(null, $limit, $limit, 100, 100));
        self::assertSame(1, $progress(null, 0, $limit + 1, 100, 100));
        self::assertSame(1, $progress(null, $limit + 1, 10, 100, 100));
    }

    /**
     * Magento's GraphQL controller answers a syntax error 400, authentication 401, authorization 403 and anything
     * else 500, each with an `errors` body the caller must see
     *
     * @param int $status
     */
    #[DataProvider('errorStatuses')]
    public function testAnErrorStatusWithAGraphqlBodyIsTheAnswer(int $status): void
    {
        $body = '{"errors":[{"message":"Syntax Error: Unexpected Name \"x\"","extensions":{"category":"graphql"}}],'
            . '"data":{"products":null}}';

        $answer = $this->client(new RecordingCurlClient($status, $body))->request('{ x }', []);

        self::assertSame(
            [
                'errors' => [
                    ['message' => 'Syntax Error: Unexpected Name "x"', 'extensions' => ['category' => 'graphql']],
                ],
                'data' => ['products' => null],
            ],
            $answer
        );
        self::assertSame(
            [sprintf('The GraphQL endpoint https://shop.test/graphql answered HTTP %d.', $status)],
            $this->logger->messages('info')
        );
    }

    /**
     * @return array<string,array{int}>
     */
    public static function errorStatuses(): array
    {
        return ['bad request' => [400], 'unauthorized' => [401], 'forbidden' => [403], 'server error' => [500]];
    }

    public function testAnErrorsOnlyAnswerIsReturned(): void
    {
        $answer = $this->client(new RecordingCurlClient(200, '{"errors":[{"message":"Nope"}]}'))->request('{ a }', []);

        self::assertSame(['errors' => [['message' => 'Nope']]], $answer);
    }

    public function testACurlFailureIsATransportFailure(): void
    {
        $curl = new RecordingCurlClient(0, '', new \Exception('Operation timed out after 30001 milliseconds'));

        try {
            $this->client($curl)->request('{ a }', []);
            self::fail('A curl failure must not be an answer.');
        } catch (GraphQlTransportException $exception) {
            self::assertStringContainsString('timed out', $exception->getMessage());
            self::assertInstanceOf(\Exception::class, $exception->getPrevious());
        }
        self::assertCount(1, $this->logger->messages('warning'));
    }

    /**
     * @param int $status
     * @param string $body
     */
    #[DataProvider('unusableBodies')]
    public function testABodyWithoutAGraphqlAnswerIsATransportFailureAndOnlyItsStartIsLogged(
        int $status,
        string $body
    ): void {
        try {
            $this->client(new RecordingCurlClient($status, $body))->request('{ a }', []);
            self::fail('The body holds no GraphQL answer.');
        } catch (GraphQlTransportException $exception) {
            self::assertStringContainsString((string) $status, $exception->getMessage());
            self::assertStringNotContainsString('<html', $exception->getMessage());
        }

        self::assertCount(1, $this->logger->records);
        $logged = $this->logger->records[0]['context']['body'] ?? null;
        self::assertIsString($logged);
        self::assertLessThanOrEqual(2000, strlen($logged));
        self::assertSame(substr($body, 0, 2000), $logged);
    }

    /**
     * @return array<string,array{int, string}>
     */
    public static function unusableBodies(): array
    {
        return [
            'html error page' => [502, '<html><body>' . str_repeat('Bad gateway ', 500) . '</body></html>'],
            'not json' => [200, 'OK'],
            'empty' => [200, ''],
            'json list' => [200, '[{"data":{}}]'],
            'json scalar' => [200, '"data"'],
            'object without data or errors' => [200, '{"message":"Method Not Allowed"}'],
            'empty object' => [200, '{}'],
        ];
    }

    public function testAnEndpointThatCannotBeNamedSendsNothing(): void
    {
        $curl = new RecordingCurlClient();

        try {
            $this->client($curl, new FakeEndpoint('', '', true))->request('{ a }', []);
            self::fail('No endpoint, no answer.');
        } catch (GraphQlTransportException) {
            self::assertSame([], $curl->requests);
            self::assertSame(0, $this->created);
        }
    }

    public function testVariablesThatCannotBeEncodedAreATransportFailure(): void
    {
        $curl = new RecordingCurlClient();

        $this->expectException(GraphQlTransportException::class);
        $this->client($curl)->request('{ a }', ['value' => NAN]);
    }

    /**
     * The client over a factory that hands out this curl client
     *
     * @param RecordingCurlClient $curl
     * @param FakeEndpoint|null $endpoint
     * @return HttpGraphQlClient
     */
    private function client(RecordingCurlClient $curl, ?FakeEndpoint $endpoint = null): HttpGraphQlClient
    {
        $factory = $this->createStub(ClientFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($curl): RecordingCurlClient {
            $this->created++;

            return $curl;
        });

        return new HttpGraphQlClient($factory, $endpoint ?? new FakeEndpoint(), $this->logger);
    }
}
