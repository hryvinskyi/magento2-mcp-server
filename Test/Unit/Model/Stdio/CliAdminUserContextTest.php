<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Stdio;

use Hryvinskyi\McpServer\Model\Stdio\CliAdminUserContext;
use Hryvinskyi\McpServer\Model\Stdio\SapiName;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\TestCase;

/**
 * The CLI admin user context answers only once an admin is set, and only in a command line
 */
class CliAdminUserContextTest extends TestCase
{
    public function testItAnswersNothingBeforeAnAdminIsSet(): void
    {
        $context = new CliAdminUserContext(new SapiName('cli'));

        self::assertNull($context->getUserType());
        self::assertNull($context->getUserId());
    }

    public function testItAnswersTheAdminOnceSet(): void
    {
        $context = new CliAdminUserContext(new SapiName('cli'));

        $context->actAs(12);

        self::assertSame(UserContextInterface::USER_TYPE_ADMIN, $context->getUserType());
        self::assertSame(12, $context->getUserId());
    }

    public function testItNeverAnswersOutsideACommandLine(): void
    {
        $context = new CliAdminUserContext(new SapiName('fpm-fcgi'));

        $context->actAs(12);

        self::assertNull($context->getUserType());
        self::assertNull($context->getUserId());
    }

    public function testThePhpServerApiIsTheDefault(): void
    {
        self::assertSame(PHP_SAPI === 'cli', (new SapiName())->isCommandLine());
    }

    public function testAnAdminIdIsPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CliAdminUserContext(new SapiName('cli')))->actAs(0);
    }
}
