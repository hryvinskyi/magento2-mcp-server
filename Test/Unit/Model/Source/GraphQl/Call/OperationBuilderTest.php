<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl\Call;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\OperationBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Call\SelectionValidator;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Operation;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\GraphQlSchemaKit as Kit;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class OperationBuilderTest extends TestCase
{
    public function testArgumentsGoOnlyIntoVariablesAndTheSelectionOnItsOwnLine(): void
    {
        $operation = $this->build(Operation::Query, $this->products(), [
            'search' => 'shirt") { injected }',
            'pageSize' => 5,
            'selection' => '{ items { sku } total_count }',
        ]);

        self::assertSame(
            "query(\$search: String, \$pageSize: Int!) {\n  products(search: \$search, pageSize: \$pageSize)\n"
            . "{ items { sku } total_count }\n}",
            $operation['document']
        );
        self::assertSame(['search' => 'shirt") { injected }', 'pageSize' => 5], $operation['variables']);
    }

    public function testArgumentsNotSentAreLeftOut(): void
    {
        $operation = $this->build(Operation::Query, $this->products(), ['selection' => '{ total_count }']);

        self::assertSame("query {\n  products\n{ total_count }\n}", $operation['document']);
        self::assertSame([], $operation['variables']);
    }

    public function testVariableTypesAreWrittenAsGraphqlWritesThem(): void
    {
        $field = Kit::field('addProductsToCart', Kit::named('OBJECT', 'Products'), [
            Kit::input('cartId', Kit::nonNull(Kit::scalar('String'))),
            Kit::input('cartItems', Kit::nonNull(Kit::listOf(Kit::nonNull(Kit::named('INPUT_OBJECT', 'CartItem'))))),
            Kit::input('codes', Kit::listOf(Kit::listOf(Kit::scalar('ID')))),
        ]);

        $operation = $this->build(Operation::Mutation, $field, [
            'cartId' => 'abc',
            'cartItems' => [['sku' => 'S1', 'quantity' => 1]],
            'codes' => [['1']],
            'selection' => '{ total_count }',
        ]);

        self::assertStringStartsWith(
            'mutation($cartId: String!, $cartItems: [CartItem!]!, $codes: [[ID]]) {',
            $operation['document']
        );
        self::assertStringContainsString(
            'addProductsToCart(cartId: $cartId, cartItems: $cartItems, codes: $codes)',
            $operation['document']
        );
    }

    public function testAnEmptyInputObjectIsSentAsAnEmptyJsonObjectAtEveryDepth(): void
    {
        $field = Kit::field('createEmptyCart', Kit::scalar('String'), [
            Kit::input('input', Kit::named('INPUT_OBJECT', 'CartInput')),
            Kit::input('ids', Kit::listOf(Kit::scalar('String'))),
        ]);
        $types = [
            Kit::inputType('CartInput', [
                Kit::input('options', Kit::named('INPUT_OBJECT', 'Options')),
                Kit::input('lines', Kit::listOf(Kit::named('INPUT_OBJECT', 'Options'))),
            ]),
            Kit::inputType('Options', [Kit::input('flag', Kit::scalar('Boolean'))]),
        ];

        $operation = $this->build(
            Operation::Mutation,
            $field,
            ['input' => ['options' => [], 'lines' => [[], ['flag' => true]]], 'ids' => []],
            $types
        );

        self::assertSame(
            '{"input":{"options":{},"lines":[{},{"flag":true}]},"ids":[]}',
            json_encode($operation['variables'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            '{}',
            json_encode(
                $this->build(Operation::Mutation, $field, ['input' => []], $types)['variables']['input'],
                JSON_THROW_ON_ERROR
            )
        );
    }

    public function testAFieldWithAScalarResultTakesNoSelection(): void
    {
        $field = Kit::field('createEmptyCart', Kit::scalar('String'));

        self::assertSame("mutation {\n  createEmptyCart\n}", $this->build(Operation::Mutation, $field, [])['document']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('takes no argument named "selection"');
        $this->build(Operation::Mutation, $field, ['selection' => '{ id }']);
    }

    public function testUnknownArgumentsAreRefusedByName(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The GraphQL field "products" takes no argument named "limit", "where".');

        $this->build(Operation::Query, $this->products(), [
            'limit' => 1,
            'search' => 'x',
            'where' => [],
            'selection' => '{ total_count }',
        ]);
    }

    public function testAFieldWithAnObjectResultNeedsASelection(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Send the selection set of the result in the argument "selection"');

        $this->build(Operation::Query, $this->products(), ['search' => 'x']);
    }

    public function testASelectionThatSmugglesASecondRootFieldIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('nothing may follow the brace that closes it');

        $this->build(Operation::Query, $this->products(), ['selection' => '{ total_count } customer { email }']);
    }

    public function testATypeThatIsNotAGraphqlNameIsNeverWrittenIntoTheDocument(): void
    {
        $field = Kit::field('lookup', Kit::scalar('String'), [
            Kit::input('text', Kit::named('SCALAR', 'String) { customer { email } } #')),
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('in a form this server cannot call');

        $this->build(Operation::Query, $field, ['text' => 'x']);
    }

    /**
     * @return array<string, mixed>
     */
    private function products(): array
    {
        return Kit::field('products', Kit::named('OBJECT', 'Products'), [
            Kit::input('search', Kit::scalar('String')),
            Kit::input('pageSize', Kit::nonNull(Kit::scalar('Int')), '20'),
            Kit::input('currentPage', Kit::scalar('Int'), '1'),
        ]);
    }

    /**
     * @param Operation $operation
     * @param array<string,mixed> $field
     * @param array<string,mixed> $arguments
     * @param list<array<string,mixed>> $types
     * @return array{document: string, variables: array<string, mixed>}
     */
    private function build(Operation $operation, array $field, array $arguments, array $types = []): array
    {
        $nodes = new NodeReader();
        $typed = $nodes->fieldsOf(['fields' => [$field]])[0] ?? null;
        self::assertNotNull($typed);

        return (new OperationBuilder($nodes, new SelectionValidator()))
            ->build($this->schema($types), $operation, $typed, $arguments);
    }

    /**
     * @param list<array<string,mixed>> $types
     * @return IntrospectedSchema
     */
    private function schema(array $types): IntrospectedSchema
    {
        return Kit::schema([], [], array_merge([Kit::objectType('Products', [])], $types));
    }
}
