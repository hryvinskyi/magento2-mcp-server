<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\HTTP\ClientInterface;

/**
 * An HTTP client that records what it is told and answers a set status and body, or fails as curl does
 */
class RecordingCurlClient implements ClientInterface
{
    /**
     * @var array<string,string>
     */
    public array $headers = [];

    /**
     * Options set through setOption() or setOptions(), by curl option
     *
     * @var array<int|string,mixed>
     */
    public array $options = [];

    /**
     * @var int|null
     */
    public ?int $timeout = null;

    /**
     * @var array<string,string>
     */
    public array $cookies = [];

    /**
     * @var bool
     */
    public bool $credentialsSet = false;

    /**
     * Every request: method, URI and body
     *
     * @var list<array{method: string, uri: string, body: mixed}>
     */
    public array $requests = [];

    /**
     * @param int $status
     * @param string $body
     * @param \Exception|null $failure Thrown by the request, as the curl client's doError() does
     */
    public function __construct(
        private readonly int $status = 200,
        private readonly string $body = '{"data":{}}',
        private readonly ?\Exception $failure = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function setTimeout($value)
    {
        $this->timeout = (int) $value;
    }

    /**
     * @param array<string,string> $headers
     * @return void
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    /**
     * @inheritDoc
     */
    public function addHeader($name, $value)
    {
        $this->headers[(string) $name] = (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function removeHeader($name)
    {
        unset($this->headers[(string) $name]);
    }

    /**
     * @inheritDoc
     */
    public function setCredentials($login, $pass)
    {
        $this->credentialsSet = true;
    }

    /**
     * @inheritDoc
     */
    public function addCookie($name, $value)
    {
        $this->cookies[(string) $name] = (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function removeCookie($name)
    {
        unset($this->cookies[(string) $name]);
    }

    /**
     * @param array<string,string> $cookies
     * @return void
     */
    public function setCookies($cookies)
    {
        $this->cookies = $cookies;
    }

    /**
     * @inheritDoc
     */
    public function removeCookies()
    {
        $this->cookies = [];
    }

    /**
     * @param string $uri
     * @return array<string,mixed> Nothing: the curl client answers through getBody()
     */
    public function get($uri)
    {
        $this->send('GET', (string) $uri, null);

        return [];
    }

    /**
     * @param string $uri
     * @param array<mixed>|string $params
     * @return void
     */
    public function post($uri, $params)
    {
        $this->send('POST', (string) $uri, $params);
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @inheritDoc
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return array<string,string>
     */
    public function getCookies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function setOption($key, $value)
    {
        $this->options[$key] = $value;
    }

    /**
     * @param array<int|string,mixed> $arr
     * @return void
     */
    public function setOptions($arr)
    {
        $this->options = $arr;
    }

    /**
     * Record a request, then fail if told to
     *
     * @param string $method
     * @param string $uri
     * @param mixed $body
     * @return void
     * @throws \Exception
     */
    private function send(string $method, string $uri, mixed $body): void
    {
        $this->requests[] = ['method' => $method, 'uri' => $uri, 'body' => $body];
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
