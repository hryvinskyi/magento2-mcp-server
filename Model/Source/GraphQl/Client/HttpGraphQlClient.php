<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends GraphQL operations over HTTP to the store's own endpoint, as a guest
 *
 * Running GraphQL through its endpoint, rather than inside the current process, runs it in the `graphql` area with
 * that area's plugins, type resolvers and context, so the answers are those of the real API.
 *
 * Every request is a fresh client from Magento's HTTP client factory, a JSON POST:
 * - no `Authorization` header and no cookies, ever: the calls are a guest's;
 * - the `Store` header names the chosen store view;
 * - it connects within 5 s and finishes within 30 s;
 * - a body over 5 MB is refused while it downloads (the progress callback aborts the transfer, and a body announced
 *   as larger is refused before it starts), so it never sits in memory whole;
 * - only HTTP and HTTPS are allowed, redirects are never followed, and TLS verification is left as curl sets it: on;
 * - `Expect: 100-continue` is never sent, so the interim answer never stands in for the real status line.
 *
 * Any status whose body is a JSON object holding `data` or `errors` is the answer; the status itself goes only to the
 * log. A failed transfer, a body that is not JSON or a JSON object with neither key is a GraphQlTransportException;
 * the start of such a body goes only to the log.
 */
class HttpGraphQlClient implements GraphQlClientInterface
{
    /**
     * Seconds to wait for the connection
     */
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Seconds the whole request may take
     */
    public const TIMEOUT_SECONDS = 30;

    /**
     * The largest response body accepted, in bytes
     */
    public const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

    /**
     * How much of an unusable body is logged, in bytes
     */
    private const LOGGED_BODY_BYTES = 2000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param ClientFactory $clientFactory
     * @param EndpointInterface $endpoint
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly EndpointInterface $endpoint,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function request(string $document, array $variables): array
    {
        $url = $this->endpoint->url();
        $client = $this->clientFactory->create();
        $this->prepare($client, $this->endpoint->storeCode());

        try {
            $client->post($url, $this->body($document, $variables));
        } catch (\Exception $exception) {
            $this->logger->warning(
                sprintf('MCP could not reach the GraphQL endpoint %s: %s', $url, $exception->getMessage()),
                ['exception' => $exception]
            );
            throw new GraphQlTransportException(
                sprintf('The GraphQL endpoint %s could not be reached: %s', $url, $exception->getMessage()),
                0,
                $exception
            );
        }

        $status = (int) $client->getStatus();
        $body = (string) $client->getBody();
        $answer = $this->answerIn($body);
        if ($answer === null) {
            $this->logger->warning(
                sprintf('MCP got no GraphQL answer from %s (HTTP %d).', $url, $status),
                ['body' => substr($body, 0, self::LOGGED_BODY_BYTES)]
            );
            throw new GraphQlTransportException(
                sprintf('The GraphQL endpoint %s answered HTTP %d without a GraphQL answer.', $url, $status)
            );
        }
        if ($status !== 200) {
            $this->logger->info(sprintf('The GraphQL endpoint %s answered HTTP %d.', $url, $status));
        }

        return $answer;
    }

    /**
     * Whether a transfer must stop: true once more than the limit has been downloaded
     *
     * Curl calls this as its transfer progress callback; a non-zero answer aborts the transfer.
     *
     * @param mixed $handle The curl handle, unused
     * @param int $downloadTotal
     * @param int $downloaded
     * @param int $uploadTotal
     * @param int $uploaded
     * @return int
     */
    public function abortPastLimit(
        mixed $handle,
        int $downloadTotal,
        int $downloaded,
        int $uploadTotal,
        int $uploaded
    ): int {
        return $downloaded > self::MAX_RESPONSE_BYTES || $downloadTotal > self::MAX_RESPONSE_BYTES ? 1 : 0;
    }

    /**
     * Headers, limits and hardening of one request
     *
     * @param ClientInterface $client
     * @param string $storeCode
     * @return void
     */
    private function prepare(ClientInterface $client, string $storeCode): void
    {
        $client->setHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Store' => $storeCode,
            'Expect' => '',
        ]);
        $client->setTimeout(self::TIMEOUT_SECONDS);
        // The client applies these after its own options, so they replace its wider protocol list
        $client->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_MAXFILESIZE => self::MAX_RESPONSE_BYTES,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => $this->abortPastLimit(...),
        ]);
    }

    /**
     * The JSON request body; no variables are sent as an empty object
     *
     * @param string $document
     * @param array<string,mixed> $variables
     * @return string
     * @throws GraphQlTransportException
     */
    private function body(string $document, array $variables): string
    {
        try {
            return json_encode(
                ['query' => $document, 'variables' => $variables === [] ? (object) [] : $variables],
                self::JSON_FLAGS
            );
        } catch (\JsonException $exception) {
            throw new GraphQlTransportException(
                'The GraphQL request could not be encoded as JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * The answer a body holds: a JSON object with `data` or `errors`, else null
     *
     * @param string $body
     * @return array<string,mixed>|null
     */
    private function answerIn(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || (!array_key_exists('data', $decoded) && !array_key_exists('errors', $decoded))) {
            return null;
        }

        $answer = [];
        foreach ($decoded as $key => $value) {
            $answer[(string) $key] = $value;
        }

        return $answer;
    }
}
