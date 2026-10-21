<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Webapi;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiInvoker;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeMetadataReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingServiceLocator;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Webapi\ServiceInputProcessor;
use Magento\Framework\Webapi\ServiceOutputProcessor;
use Magento\Framework\Webapi\Validator\EntityArrayValidator\InputArraySizeLimitValue;
use Magento\Webapi\Controller\Rest\ParamsOverrider;
use PHPUnit\Framework\TestCase;

/**
 * Two steps of a Web API call that come before the service: a secure route needs HTTPS, and the record id of an
 * update route moves from the arguments into the record, as REST moves the URL's value
 */
class WebapiInvokerTest extends TestCase
{
    private const SERVICE = 'Acme\Shop\Api\ThingRepositoryInterface';

    /**
     * Arguments the input processor was handed, one list per call
     *
     * @var list<array<mixed>>
     */
    private array $processed = [];

    /**
     * Path ids and body the record id step was handed, one pair per call
     *
     * @var list<array{array<mixed>, array<mixed>}>
     */
    private array $moved = [];

    /**
     * @var RecordingServiceLocator
     */
    private RecordingServiceLocator $locator;

    protected function setUp(): void
    {
        $this->locator = new RecordingServiceLocator(new class {
            /**
             * @param mixed ...$arguments
             * @return array<mixed>
             */
            public function save(mixed ...$arguments): array
            {
                return $arguments;
            }
        });
    }

    public function testASecureRouteIsRefusedOverPlainHttpBeforeAnythingRuns(): void
    {
        $invoker = $this->invoker($this->httpRequest(false));

        try {
            $invoker->invoke($this->ability(), $this->route('POST', true), ['entity' => ['name' => 'x']]);
            self::fail('A secure route must not run over plain HTTP.');
        } catch (LocalizedException $exception) {
            self::assertSame('This ability must be called over HTTPS.', $exception->getMessage());
        }

        self::assertSame([], $this->processed);
        self::assertSame([], $this->locator->asked);
    }

    public function testASecureRouteRunsOverHttps(): void
    {
        $output = $this->invoker($this->httpRequest(true))
            ->invoke($this->ability(), $this->route('POST', true), ['entity' => ['name' => 'x']]);

        self::assertSame([['name' => 'x']], $output);
        self::assertSame([self::SERVICE], $this->locator->asked);
    }

    public function testARouteThatIsNotSecureRunsOverPlainHttp(): void
    {
        $output = $this->invoker($this->httpRequest(false))
            ->invoke($this->ability(), $this->route('POST', false), ['entity' => ['name' => 'x']]);

        self::assertSame([['name' => 'x']], $output);
    }

    public function testWithoutAnHttpRequestASecureRouteRuns(): void
    {
        $output = $this->invoker($this->createStub(RequestInterface::class))
            ->invoke($this->ability(), $this->route('POST', true), ['entity' => ['name' => 'x']]);

        self::assertSame([['name' => 'x']], $output);
    }

    public function testTheRecordIdOfAnUpdateRouteMovesIntoTheRecord(): void
    {
        $this->invoker($this->httpRequest(false))->invoke(
            $this->ability(),
            $this->route('PUT', false),
            ['parent_id' => 7, 'entity' => ['name' => 'x']]
        );

        self::assertSame([[['parent_id' => 7], ['entity' => ['name' => 'x']]]], $this->moved);
        self::assertSame([['entity' => ['name' => 'x', 'parent_id' => 7]]], $this->processed);
    }

    public function testAnUpdateWithoutTheRecordIdIsLeftAsSent(): void
    {
        $this->invoker($this->httpRequest(false))
            ->invoke($this->ability(), $this->route('PUT', false), ['entity' => ['name' => 'x']]);

        self::assertSame([], $this->moved);
        self::assertSame([['entity' => ['name' => 'x']]], $this->processed);
    }

    public function testACreateRouteKeepsAPathValueWhereTheCallerPutIt(): void
    {
        $this->invoker($this->httpRequest(false))
            ->invoke($this->ability(), $this->route('POST', false), ['parent_id' => 7, 'entity' => ['name' => 'x']]);

        self::assertSame([], $this->moved);
        self::assertSame([['parent_id' => 7, 'entity' => ['name' => 'x']]], $this->processed);
    }

    /**
     * @param RequestInterface $request
     * @return WebapiInvoker
     */
    private function invoker(RequestInterface $request): WebapiInvoker
    {
        $overrider = $this->createStub(ParamsOverrider::class);
        $overrider->method('override')->willReturnArgument(0);
        $overrider->method('overrideRequestBodyIdWithPathParam')->willReturnCallback(
            function (array $pathIds, array $body): array {
                $this->moved[] = [$pathIds, $body];
                $body['entity'] = (array) ($body['entity'] ?? []) + $pathIds;

                return $body;
            }
        );
        $input = $this->createStub(ServiceInputProcessor::class);
        $input->method('process')->willReturnCallback(function (string $class, string $method, array $data): array {
            $this->processed[] = $data;

            return array_values(array_intersect_key($data, ['entity' => true]));
        });
        $output = $this->createStub(ServiceOutputProcessor::class);
        $output->method('process')->willReturnArgument(0);

        return new WebapiInvoker(
            $overrider,
            $input,
            $output,
            $this->locator,
            $this->createStub(InputArraySizeLimitValue::class),
            new RecordingLogger(),
            $request,
            new FakeMetadataReader([self::SERVICE . '::save' => [
                'documentation' => '',
                'parameters' => [
                    'entity' => ['type' => 'AcmeShopDataThingInterface', 'required' => true, 'documentation' => ''],
                ],
            ]])
        );
    }

    /**
     * @param bool $secure
     * @return HttpRequest
     */
    private function httpRequest(bool $secure): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('isSecure')->willReturn($secure);

        return $request;
    }

    /**
     * @param string $httpMethod
     * @param bool $secure
     * @return Route
     */
    private function route(string $httpMethod, bool $secure): Route
    {
        return new Route(
            '/V1/things/:parent_id',
            $httpMethod,
            self::SERVICE,
            'save',
            ['Acme_Shop::things'],
            null,
            $secure
        );
    }

    /**
     * @return Ability
     */
    private function ability(): Ability
    {
        return new Ability(
            'acme_shop.thing_repository.save',
            'Save a thing',
            'Acme_Shop',
            'webapi',
            'PUT /V1/things/:parent_id',
            AbilityAccess::Write,
            ['Acme_Shop::things']
        );
    }
}
