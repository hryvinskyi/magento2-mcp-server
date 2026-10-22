<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Transport;

use Hryvinskyi\McpServer\Model\Transport\ChannelSecurity;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * A channel is secure over HTTPS or once marked a local pipe, and not over plain HTTP
 */
class ChannelSecurityTest extends TestCase
{
    public function testPlainHttpIsNotSecure(): void
    {
        self::assertFalse((new ChannelSecurity($this->httpRequest(false)))->isSecure());
    }

    public function testHttpsIsSecure(): void
    {
        self::assertTrue((new ChannelSecurity($this->httpRequest(true)))->isSecure());
    }

    public function testALocalPipeIsSecureWhateverTheRequestSays(): void
    {
        $channel = new ChannelSecurity($this->httpRequest(false));

        $channel->markLocalChannel();

        self::assertTrue($channel->isSecure());
    }

    public function testARequestThatIsNotAnHttpOneCarriesNothingOverTheNetwork(): void
    {
        self::assertTrue((new ChannelSecurity($this->createStub(RequestInterface::class)))->isSecure());
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
}
