<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Call;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\SelectionValidator;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SelectionValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function oneGroup(): array
    {
        return [
            'flat' => ['{ total_count }'],
            'nested' => ['{ items { sku name price_range { minimum_price { final_price { value } } } } total_count }'],
            'leading and trailing whitespace' => ["\n\t { items { sku } }  \r\n"],
            'inline fragment' => ['{ items { sku ... on ConfigurableProduct { variants { product { sku } } } } }'],
            'aliases, arguments and directives' => ['{ first: items @include(if: true) { sku } }'],
            'braces in a string' => ['{ items { description(format: "}{ } }") { html } } }'],
            'escaped quote and brace in a string' => ['{ a(x: "say \"}\" twice") { b } }'],
            'a hash in a string' => ['{ a(x: "#not a comment") { b } }'],
            'braces in a block string' => ["{ a(x: \"\"\"\n } closing { \n\"\"\") { b } }"],
            'escaped block quote in a block string' => ['{ a(x: """ \""" } """) { b } }'],
        ];
    }

    #[DataProvider('oneGroup')]
    public function testAcceptsExactlyOneGroupInBraces(string $selection): void
    {
        (new SelectionValidator())->validate($selection);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function smuggled(): array
    {
        return [
            'a second root field after the group' => ['{ items { sku } } customer { email }', 'nothing may follow'],
            'a short second root field' => ['{ a } b { c }', 'nothing may follow'],
            'a second mutation' => [
                '{ cart { id } } placeOrder(input: { cart_id: "x" }) { orderV2 { number } }',
                'nothing may follow',
            ],
            'a fragment definition after the group' => [
                '{ ...F } fragment F on Products { total_count }',
                'nothing may follow',
            ],
            'an operation keyword before the group' => ['query { products { total_count } }', 'start with'],
            'no braces' => ['items', 'start with'],
            'empty' => ['   ', 'start with'],
            'a comment' => ["{ items { sku } # }\n}", 'comments'],
            'a comment hiding a closing brace' => ["{ a { b } #\n} c { d }", 'comments'],
            'unbalanced braces' => ['{ items { sku }', 'not balanced'],
            'an unclosed string' => ['{ a(x: "}) { b } }', 'not closed'],
            'a string across a line break' => ["{ a(x: \"}\n\") { b } }", 'not closed'],
            'an unclosed block string' => ['{ a(x: """ } ) { b } }', 'not closed'],
        ];
    }

    #[DataProvider('smuggled')]
    public function testRefusesAnythingButOneGroup(string $selection, string $reason): void
    {
        try {
            (new SelectionValidator())->validate($selection);
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('exactly one GraphQL selection set', $exception->getMessage());
            self::assertStringContainsString($reason, $exception->getMessage());

            return;
        }

        self::fail('The selection was accepted.');
    }
}
