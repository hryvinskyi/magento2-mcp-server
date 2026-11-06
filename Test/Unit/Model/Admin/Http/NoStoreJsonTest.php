<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Http;

use Hryvinskyi\McpServer\Model\Admin\Http\NoStoreJson;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\Response;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Translate\InlineInterface;
use PHPUnit\Framework\TestCase;

/**
 * The JSON answer of the MCP Server page's admin routes, rendered into a real HTTP response: the status and body asked
 * for, kept by no cache and read by no browser as anything but JSON
 */
class NoStoreJsonTest extends TestCase
{
    public function testTheAnswerCarriesTheStatusTheBodyAndTheHeadersNoCacheOrSniffingGetsPast(): void
    {
        $response = $this->rendered(500, ['error' => 'The section System could not be switched.']);

        self::assertSame(500, $response->getHttpResponseCode());
        self::assertSame('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame('no-cache', $this->header($response, 'Pragma'));
        self::assertSame('nosniff', $this->header($response, 'X-Content-Type-Options'));
        self::assertSame('application/json', $this->header($response, 'Content-Type'));
        self::assertSame(
            ['error' => 'The section System could not be switched.'],
            json_decode((string) $response->getBody(), true)
        );
    }

    public function testANestedBodyIsEncodedAsItIs(): void
    {
        $body = [
            'message' => 'Switched.',
            'exposed' => 12,
            'sections' => [['code' => 'Magento_Backend::system', 'off' => true, 'exposed' => 0]],
        ];

        $response = $this->rendered(200, $body);

        self::assertSame(200, $response->getHttpResponseCode());
        self::assertSame($body, json_decode((string) $response->getBody(), true));
    }

    public function testTheHeadersReplaceAnyTheResultAlreadyHad(): void
    {
        $result = $this->jsonResult();
        $result->setHeader('Cache-Control', 'max-age=86400');

        $response = new Response();
        (new NoStoreJson($this->factoryOf($result)))->answer(200, [])->renderResult($response);

        self::assertSame('no-store', $this->header($response, 'Cache-Control'));
    }

    /**
     * The answer rendered into a fresh HTTP response
     *
     * @param int $status
     * @param array<string, mixed> $body
     * @return Response
     */
    private function rendered(int $status, array $body): Response
    {
        $response = new Response();
        (new NoStoreJson($this->factoryOf($this->jsonResult())))->answer($status, $body)->renderResult($response);

        return $response;
    }

    /**
     * Magento's JSON result, with inline translation doing nothing
     *
     * @return Json
     */
    private function jsonResult(): Json
    {
        return new Json($this->createStub(InlineInterface::class), new JsonSerializer());
    }

    /**
     * A result factory that hands out this result for a JSON answer, and fails for any other type
     *
     * @param Json $result
     * @return ResultFactory
     */
    private function factoryOf(Json $result): ResultFactory
    {
        $factory = $this->createMock(ResultFactory::class);
        $factory->expects(self::once())
            ->method('create')
            ->with(ResultFactory::TYPE_JSON)
            ->willReturn($result);

        return $factory;
    }

    /**
     * A header's value, which must be sent once
     *
     * @param Response $response
     * @param string $name
     * @return string
     */
    private function header(Response $response, string $name): string
    {
        $header = $response->getHeader($name);
        self::assertInstanceOf(HeaderInterface::class, $header, $name . ' is sent once');

        return $header->getFieldValue();
    }
}
