<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Confirmation;

use Hryvinskyi\McpServer\Model\Confirmation\ArgumentsFingerprint;
use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two calls share a fingerprint exactly when their arguments are the same value, whatever order their keys came in
 */
class ArgumentsFingerprintTest extends TestCase
{
    /**
     * @var ArgumentsFingerprint
     */
    private ArgumentsFingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = new ArgumentsFingerprint(new JsonEncoder());
    }

    public function testItIsTheSha256OfTheCanonicalJson(): void
    {
        self::assertSame(
            hash('sha256', '{"a":{"x":1,"y":[3,1]},"b":"é/"}'),
            $this->fingerprint->of(['b' => 'é/', 'a' => ['y' => [3, 1], 'x' => 1]])
        );
    }

    public function testTheOrderOfKeysDoesNotMatterAtAnyDepth(): void
    {
        self::assertSame(
            $this->fingerprint->of([
                'product' => ['sku' => 'A1', 'extension_attributes' => ['stock' => ['qty' => 2, 'in_stock' => true]]],
                'saveOptions' => false,
            ]),
            $this->fingerprint->of([
                'saveOptions' => false,
                'product' => ['extension_attributes' => ['stock' => ['in_stock' => true, 'qty' => 2]], 'sku' => 'A1'],
            ])
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function differentArguments(): array
    {
        return [
            'another value' => [['sku' => 'A1'], ['sku' => 'A2']],
            'another type' => [['qty' => 1], ['qty' => '1']],
            'an integer and a float' => [['qty' => 1], ['qty' => 1.0]],
            'an extra key' => [['sku' => 'A1'], ['sku' => 'A1', 'qty' => 1]],
            'a list in another order' => [['ids' => [1, 2]], ['ids' => [2, 1]]],
            'a nested value' => [['p' => ['a' => ['b' => 1]]], ['p' => ['a' => ['b' => 2]]]],
            'no arguments and one' => [[], ['a' => null]],
        ];
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('differentArguments')]
    public function testDifferentArgumentsHaveDifferentFingerprints(array $first, array $second): void
    {
        self::assertNotSame($this->fingerprint->of($first), $this->fingerprint->of($second));
    }

    public function testItIsSixtyFourLowercaseHexadecimalCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $this->fingerprint->of([]));
    }
}
