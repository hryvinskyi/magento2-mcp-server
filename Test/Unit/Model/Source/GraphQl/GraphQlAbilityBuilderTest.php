<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlAbilityBuilder;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Operation;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\GraphQlSchemaKit as Kit;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class GraphQlAbilityBuilderTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testAQueryFieldIsAReadAbilityOfTheGraphqlCategoryRunAsAGuest(): void
    {
        $ability = $this->forField(Operation::Query, Kit::field(
            'products',
            Kit::named('OBJECT', 'Products'),
            [],
            'Search for products that match the criteria. Results are paged.'
        ));

        self::assertNotNull($ability);
        self::assertSame('graphql.query.products', $ability->getName());
        self::assertSame('Search for products that match the criteria', $ability->getTitle());
        self::assertSame('Search for products that match the criteria. Results are paged.', $ability->getDescription());
        self::assertSame('GraphQL', $ability->getCategory());
        self::assertSame('graphql', $ability->getSource());
        self::assertSame('query products', $ability->getSourceReference());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['anonymous'], $ability->getAclResources());
    }

    public function testAMutationFieldWritesAndADeprecatedOneSaysWhy(): void
    {
        $ability = $this->forField(Operation::Mutation, Kit::field(
            'createEmptyCart',
            Kit::scalar('String'),
            [],
            '',
            'Use `Mutation.createGuestCart`'
        ));

        self::assertNotNull($ability);
        self::assertSame('graphql.mutation.create_empty_cart', $ability->getName());
        self::assertSame('Create empty cart', $ability->getTitle());
        self::assertSame("Deprecated: Use `Mutation.createGuestCart`", $ability->getDescription());
        self::assertSame('mutation createEmptyCart', $ability->getSourceReference());
        self::assertSame(AbilityAccess::Write, $ability->getAccess());
    }

    #[TestWith(['customAttributeMetadataV2', 'graphql.query.custom_attribute_metadata_v2'])]
    #[TestWith(['getPaymentSDK', 'graphql.query.get_payment_sdk'])]
    #[TestWith(['cmsBlocks', 'graphql.query.cms_blocks'])]
    #[TestWith(['storeConfig', 'graphql.query.store_config'])]
    public function testNamesAreSnakeCase(string $field, string $name): void
    {
        $ability = $this->forField(Operation::Query, Kit::field($field, Kit::scalar('String')));

        self::assertSame($name, $ability?->getName());
    }

    #[TestWith(['_internal'])]
    #[TestWith(['double__underscore'])]
    #[TestWith(['bad-name'])]
    public function testAFieldThatGivesNoValidAbilityNameIsSkippedWithAWarning(string $field): void
    {
        self::assertNull($this->forField(Operation::Query, Kit::field($field, Kit::scalar('String'))));
        self::assertCount(1, $this->logger->messages('warning'));
        self::assertStringContainsString('query ' . $field, $this->logger->messages('warning')[0]);
    }

    public function testTheTypeDescriptionHelperIsAGuestReadAbility(): void
    {
        $ability = $this->builder()->forTypeDescription('graphql');

        self::assertSame('graphql.schema.describe_type', $ability->getName());
        self::assertSame('GraphQL', $ability->getCategory());
        self::assertSame(AbilityAccess::Read, $ability->getAccess());
        self::assertSame(['anonymous'], $ability->getAclResources());
    }

    /**
     * @param Operation $operation
     * @param array<string,mixed> $field
     * @return AbilityInterface|null
     */
    private function forField(Operation $operation, array $field): ?AbilityInterface
    {
        $typed = (new NodeReader())->fieldsOf(['fields' => [$field]])[0] ?? null;
        self::assertNotNull($typed);

        return $this->builder()->forField($operation, $typed, 'graphql');
    }

    /**
     * @return GraphQlAbilityBuilder
     */
    private function builder(): GraphQlAbilityBuilder
    {
        return new GraphQlAbilityBuilder(
            new AbilityInterfaceFactory(new ConstructingObjectManager([AbilityInterface::class => Ability::class])),
            new NodeReader(),
            $this->logger
        );
    }
}
