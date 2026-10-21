<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Tool\DiscoverAbilities;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedExposurePolicy;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `discover_abilities` over a fake catalog: search, filters, paging, exposure, and the arguments it refuses
 */
class DiscoverAbilitiesTest extends TestCase
{
    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
    }

    public function testWithoutArgumentsEveryAbilityIsListedByNameWithItsExposure(): void
    {
        $result = $this->tool(Exposure::NeedsConfirmation)->call([], $this->kit->callContext());

        self::assertFalse($result['isError']);
        self::assertSame(
            [
                'total' => 3,
                'abilities' => [
                    [
                        'name' => 'catalog.product_repository.get_list',
                        'title' => 'Product list',
                        'category' => 'Magento_Catalog',
                        'access' => 'read',
                        'exposure' => 'needs_confirmation',
                    ],
                    [
                        'name' => 'catalog.product_repository.save',
                        'title' => 'Save a product',
                        'category' => 'Magento_Catalog',
                        'access' => 'write',
                        'exposure' => 'needs_confirmation',
                    ],
                    [
                        'name' => 'directory.country_information_acquirer.get_countries_info',
                        'title' => 'Countries',
                        'category' => 'Magento_Directory',
                        'access' => 'read',
                        'exposure' => 'needs_confirmation',
                    ],
                ],
            ],
            $result['structuredContent']
        );
    }

    public function testTheQueryCategoryAndAccessFilterAndTheResultIsPaged(): void
    {
        $tool = $this->tool();

        $byQuery = $tool->call(['query' => 'COUNTRY'], $this->kit->callContext());
        $byFilters = $tool->call(['category' => 'Magento_Catalog', 'access' => 'write'], $this->kit->callContext());
        $paged = $tool->call(['limit' => 1, 'offset' => 1], $this->kit->callContext());

        self::assertSame(
            ['directory.country_information_acquirer.get_countries_info'],
            $this->names($byQuery['structuredContent'])
        );
        self::assertSame(['catalog.product_repository.save'], $this->names($byFilters['structuredContent']));
        self::assertSame(['catalog.product_repository.save'], $this->names($paged['structuredContent']));
        self::assertIsArray($paged['structuredContent']);
        self::assertSame(3, $paged['structuredContent']['total']);
    }

    public function testAnEmptyCategoryOrAccessFiltersNothing(): void
    {
        $result = $this->tool()->call(['category' => '', 'access' => '', 'query' => null], $this->kit->callContext());

        self::assertIsArray($result['structuredContent']);
        self::assertSame(3, $result['structuredContent']['total']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedArguments(): array
    {
        return [
            'unknown access' => [['access' => 'admin'], 'access'],
            'limit zero' => [['limit' => 0], 'limit'],
            'limit over 100' => [['limit' => 101], 'limit'],
            'limit as a string' => [['limit' => '5'], 'limit'],
            'negative offset' => [['offset' => -1], 'offset'],
            'query not a string' => [['query' => ['a']], 'query'],
        ];
    }

    /**
     * @param array<string,mixed> $arguments
     * @param string $named
     */
    #[DataProvider('refusedArguments')]
    public function testAnArgumentOfTheWrongTypeOrRangeIsRefusedNamingIt(array $arguments, string $named): void
    {
        try {
            $this->tool()->call($arguments, $this->kit->callContext());
            self::fail('The argument must be refused.');
        } catch (InvalidToolArgumentException $exception) {
            self::assertStringContainsString('"' . $named . '"', $exception->getMessage());
        }
    }

    public function testTheToolDescribesItselfAsAReadOnlySearch(): void
    {
        $tool = $this->tool();

        self::assertSame('discover_abilities', $tool->getName());
        self::assertSame(true, $tool->getAnnotations()['readOnlyHint']);
        $schema = $tool->getInputSchema();
        self::assertSame('object', $schema['type']);
        self::assertIsArray($schema['properties']);
        self::assertSame(['query', 'category', 'access', 'limit', 'offset'], array_keys($schema['properties']));
    }

    /**
     * @param Exposure $exposure
     * @return DiscoverAbilities
     */
    private function tool(Exposure $exposure = Exposure::Exposed): DiscoverAbilities
    {
        return new DiscoverAbilities(
            $this->kit->catalog(),
            new FixedExposurePolicy($exposure),
            $this->kit->argumentReader(),
            $this->kit->toolResults()
        );
    }

    /**
     * The ability names of a structured result
     *
     * @param mixed $structured
     * @return list<mixed>
     */
    private function names(mixed $structured): array
    {
        self::assertIsArray($structured);
        self::assertIsArray($structured['abilities']);

        return array_column($structured['abilities'], 'name');
    }
}
