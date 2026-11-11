<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ResultProjection;
use PHPUnit\Framework\TestCase;

/**
 * Which fields of a result a list of field paths keeps, and which paths it reports as naming no field
 */
class ResultProjectionTest extends TestCase
{
    /**
     * @var ResultProjection
     */
    private ResultProjection $projection;

    protected function setUp(): void
    {
        $this->projection = new ResultProjection();
    }

    public function testAFieldPathIsSplitIntoItsNamesAroundTheDots(): void
    {
        self::assertSame(
            [['items', 'sku'], ['total_count']],
            $this->projection->paths(['items.sku', ' total_count '])
        );
    }

    public function testAPathWithAnEmptyNameIsRefused(): void
    {
        $this->expectException(InvalidToolArgumentException::class);
        $this->expectExceptionMessage('"items."');

        $this->projection->paths(['items.']);
    }

    public function testANamedFieldKeepsEverythingUnderIt(): void
    {
        $value = ['order' => ['id' => 1, 'items' => [['sku' => 'A']]], 'other' => 2];

        self::assertSame(
            ['order' => ['id' => 1, 'items' => [['sku' => 'A']]]],
            $this->projection->project($value, $this->projection->paths(['order']))
        );
    }

    public function testAPathGoesThroughListsWithinLists(): void
    {
        $value = ['groups' => [[['sku' => 'A', 'qty' => 1]], [['sku' => 'B', 'qty' => 2]]]];

        self::assertSame(
            ['groups' => [[['sku' => 'A']], [['sku' => 'B']]]],
            $this->projection->project($value, $this->projection->paths(['groups.sku']))
        );
    }

    public function testAResultThatIsAListIsProjectedElementByElement(): void
    {
        $value = [['id' => 1, 'code' => 'default', 'name' => 'Default'], ['id' => 2, 'code' => 'trade']];

        self::assertSame(
            [['code' => 'default'], ['code' => 'trade']],
            $this->projection->project($value, $this->projection->paths(['code']))
        );
        self::assertSame([], $this->projection->misses($value, $this->projection->paths(['code'])));
    }

    public function testObjectsAreProjectedAsTheJsonEncoderSeesThem(): void
    {
        $serializable = new class implements \JsonSerializable {
            /**
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return ['sku' => 'A', 'secret' => 'kept out'];
            }
        };
        $plain = new class {
            public string $sku = 'B';
            public string $name = 'Beta';
        };

        self::assertSame(
            ['items' => [['sku' => 'A'], ['sku' => 'B']]],
            $this->projection->project(['items' => [$serializable, $plain]], $this->projection->paths(['items.sku']))
        );
    }

    public function testAValueThatIsNoObjectIsKeptAsItIs(): void
    {
        $paths = $this->projection->paths(['sku.code']);

        self::assertSame(['sku' => 'A'], $this->projection->project(['sku' => 'A'], $paths));
        self::assertSame(42, $this->projection->project(42, $paths));
        self::assertSame([], $this->projection->misses(['sku' => 'A'], $paths));
        self::assertSame([], $this->projection->misses(42, $paths));
    }

    public function testAFieldOfAnyElementOfAListCountsAsFound(): void
    {
        $value = ['items' => [['sku' => 'A'], ['sku' => 'B', 'special_price' => 5]]];

        self::assertSame([], $this->projection->misses($value, $this->projection->paths(['items.special_price'])));
    }

    public function testAnEmptyListHidesNoMissingField(): void
    {
        $value = ['items' => [], 'total_count' => 0];

        self::assertSame([], $this->projection->misses($value, $this->projection->paths(['items.sku'])));
    }

    public function testAMissingFieldDeepInTheResultNamesTheFieldsWhereThePathLeftIt(): void
    {
        $value = ['items' => [['extension_attributes' => ['stock' => 1, 'website_ids' => [1]]]]];

        self::assertSame(
            ['The result has no field "items.extension_attributes.stock_item". The fields under '
                . '"items.extension_attributes" are: stock, website_ids.'],
            $this->projection->misses($value, $this->projection->paths(['items.extension_attributes.stock_item']))
        );
    }
}
