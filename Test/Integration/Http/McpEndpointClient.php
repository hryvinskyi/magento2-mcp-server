<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Http;

use Laminas\Http\Headers;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Framework\Webapi\Rest\Response as RestResponse;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Webapi\Controller\Rest;
use Magento\Webapi\Model\Authorization\TokenUserContext;
use PHPUnit\Framework\Assert;

/**
 * Sends requests to the MCP endpoint through Magento's own REST front controller, the way a web server would
 *
 * The controller reads only the path from the request it is handed. Its router, request validator and request
 * processors read the shared REST request, so the method, body and headers go there. The token user context reads the
 * Authorization header from the application request, a different instance under the test framework, so the header
 * goes on both. Each send first makes the shared user contexts forget the caller they resolved last and drops the
 * runtime ACL, whose rules Magento loads for the role of the caller it was built for; it then clears the shared
 * response's status, headers and body; the exceptions a response collected cannot be cleared, so a request
 * Magento itself refuses belongs in a test of its own.
 */
class McpEndpointClient
{
    public const ENDPOINT = '/rest/all/V1/mcp';

    /**
     * @var string
     */
    private string $token = '';

    /**
     * Send every following request with this bearer token, or with none for ''
     *
     * @param string $token
     * @return void
     */
    public function authenticateWith(string $token): void
    {
        $this->token = $token;
    }

    /**
     * POST a body of JSON-RPC messages
     *
     * @param string $body
     * @param array<string, string> $headers
     * @param string $path
     * @return RestResponse
     */
    public function post(string $body, array $headers = [], string $path = self::ENDPOINT): RestResponse
    {
        return $this->send(HttpRequest::METHOD_POST, $path, $body, $headers);
    }

    /**
     * GET the endpoint
     *
     * @param array<string, string> $headers
     * @return RestResponse
     */
    public function get(array $headers = []): RestResponse
    {
        return $this->send(HttpRequest::METHOD_GET, self::ENDPOINT, '', $headers);
    }

    /**
     * DELETE the endpoint
     *
     * @param array<string, string> $headers
     * @return RestResponse
     */
    public function delete(array $headers = []): RestResponse
    {
        return $this->send(HttpRequest::METHOD_DELETE, self::ENDPOINT, '', $headers);
    }

    /**
     * Dispatch one request through the REST front controller
     *
     * @param string $method
     * @param string $path
     * @param string $body
     * @param array<string, string> $headers
     * @return RestResponse
     */
    private function send(string $method, string $path, string $body, array $headers): RestResponse
    {
        $this->forgetCaller();
        $this->clearResponse();

        $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json']
            + $headers;
        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $restRequest = self::service(RestRequest::class);
        $restRequest->setMethod($method);
        $restRequest->setContent($body);
        $restHeaders = $restRequest->getHeaders();
        Assert::assertInstanceOf(Headers::class, $restHeaders);
        $restHeaders->clearHeaders();
        foreach ($headers as $name => $value) {
            $restHeaders->addHeaderLine($name, $value);
        }

        $applicationRequest = self::service(RequestInterface::class);
        Assert::assertInstanceOf(HttpRequest::class, $applicationRequest);
        $applicationHeaders = $applicationRequest->getHeaders();
        Assert::assertInstanceOf(Headers::class, $applicationHeaders);
        $applicationHeaders->clearHeaders();
        if ($this->token !== '') {
            $applicationHeaders->addHeaderLine('Authorization', 'Bearer ' . $this->token);
        }

        $request = Bootstrap::getObjectManager()->create(HttpRequest::class);
        Assert::assertInstanceOf(HttpRequest::class, $request);
        $request->setRequestUri($path);
        $request->setPathInfo($path);
        $request->setUri('http://localhost' . $path);
        $request->setMethod($method);

        $response = self::service(Rest::class)->dispatch($request);
        Assert::assertInstanceOf(RestResponse::class, $response);

        return $response;
    }

    /**
     * Make the shared user contexts resolve the caller again from the next request's token, and the ACL be built for
     * that caller's role
     *
     * @return void
     */
    private function forgetCaller(): void
    {
        self::service(TokenUserContext::class)->_resetState();
        $userContext = self::service(UserContextInterface::class);
        Assert::assertInstanceOf(ResetAfterRequestInterface::class, $userContext);
        $userContext->_resetState();
        self::service(AclBuilder::class)->resetRuntimeAcl();
    }

    /**
     * Clear what the previous request left on the shared response: status, headers and body
     *
     * @return void
     */
    private function clearResponse(): void
    {
        $response = self::service(RestResponse::class);
        $response->setHttpResponseCode(200);
        $response->clearHeaders();
        $response->setBody('');
    }

    /**
     * A shared instance from the container of the current area, of the class asked for
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        Assert::assertInstanceOf($class, $service);

        return $service;
    }
}
