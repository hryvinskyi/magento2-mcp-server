<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Exposure\Rule;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Exposure\Rule\BuiltInDenyListRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ability-name globs: `*` spans the dots between parts, and a pattern must match the whole name
 */
class BuiltInDenyListRuleTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function names(): iterable
    {
        yield 'a module glob covers every ability of the module' => [
            'integration.*',
            'integration.admin_token_service.create_admin_access_token',
            true,
        ];
        yield 'a module glob covers a customer token issuer' => [
            'integration.*',
            'integration.customer_token_service.create_customer_access_token',
            true,
        ];
        yield 'a module glob does not reach a module whose name only starts the same' => [
            'integration.*',
            'integration_tools.export_service.run',
            false,
        ];
        yield 'a module glob does not reach a service of the same name in another module' => [
            'integration.*',
            'catalog.integration.get',
            false,
        ];
        yield 'a prefix glob covers each module sharing the prefix' => [
            'login_as_customer*.*',
            'login_as_customer_api.login_as_customer_service.execute',
            true,
        ];
        yield 'a prefix glob covers the module named by the prefix' => [
            'login_as_customer*.*',
            'login_as_customer.config.is_enabled',
            true,
        ];
        yield 'a method glob covers a password reset' => [
            'customer.account_management.*password*',
            'customer.account_management.reset_password',
            true,
        ];
        yield 'a method glob covers a password reset request' => [
            'customer.account_management.*password*',
            'customer.account_management.initiate_password_reset',
            true,
        ];
        yield 'a method glob leaves the other methods of the service' => [
            'customer.account_management.*password*',
            'customer.account_management.is_email_available',
            false,
        ];
        yield 'a method glob leaves the same method of another service' => [
            'customer.account_management.*password*',
            'customer.customer_repository.reset_password',
            false,
        ];
    }

    #[DataProvider('names')]
    public function testMatchesTheWholeName(string $pattern, string $name, bool $matches): void
    {
        $rule = new BuiltInDenyListRule(['reason' => $pattern]);

        self::assertSame($matches, $rule->matches($name));
        self::assertSame($matches ? Exposure::Denied : null, $rule->exposureOf($this->ability($name)));
    }

    public function testAnyPatternOfTheListIsEnough(): void
    {
        $rule = new BuiltInDenyListRule([
            'token_issuers' => 'integration.*',
            'two_factor_authentication' => 'two_factor_auth.*',
        ]);

        self::assertTrue($rule->matches('two_factor_auth.tfa.get_user_config'));
        self::assertTrue($rule->matches('integration.admin_token_service.create_admin_access_token'));
        self::assertFalse($rule->matches('catalog.product_repository.get_list'));
    }

    public function testAPatternAnotherModuleDroppedMatchesNothing(): void
    {
        $rule = new BuiltInDenyListRule(['token_issuers' => null, 'blank' => '']);

        self::assertFalse($rule->matches('integration.admin_token_service.create_admin_access_token'));
        self::assertNull(
            $rule->exposureOf($this->ability('integration.admin_token_service.create_admin_access_token'))
        );
    }

    public function testAnEmptyListDeniesNothing(): void
    {
        $rule = new BuiltInDenyListRule();

        self::assertFalse($rule->matches('integration.admin_token_service.create_admin_access_token'));
    }

    /**
     * @param string $name
     * @return Ability
     */
    private function ability(string $name): Ability
    {
        return new Ability($name, 'Title', 'Magento_Integration', 'webapi', 'POST /V1/x', AbilityAccess::Write);
    }
}
