<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Api\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function testOnlyAReadAbilityLeavesTheDataUnchanged(): void
    {
        self::assertTrue(AbilityAccess::Read->isReadOnly());
        self::assertFalse(AbilityAccess::Write->isReadOnly());
        self::assertFalse(AbilityAccess::Delete->isReadOnly());
    }

    public function testAccessValuesAreTheWordsClientsSee(): void
    {
        self::assertSame(['read', 'write', 'delete'], array_column(AbilityAccess::cases(), 'value'));
    }

    public function testAnExposedAbilityAndOneNeedingConfirmationCanBeCalled(): void
    {
        self::assertTrue(Exposure::Exposed->allowsCall());
        self::assertTrue(Exposure::NeedsConfirmation->allowsCall());
    }

    public function testABlockedOrDeniedAbilityCannotBeCalled(): void
    {
        self::assertFalse(Exposure::ReadOnlyBlocked->allowsCall());
        self::assertFalse(Exposure::Denied->allowsCall());
    }

    public function testRestrictivenessFollowsThePrecedence(): void
    {
        self::assertSame(0, Exposure::Exposed->restrictiveness());
        self::assertSame(1, Exposure::NeedsConfirmation->restrictiveness());
        self::assertSame(2, Exposure::ReadOnlyBlocked->restrictiveness());
        self::assertSame(3, Exposure::Denied->restrictiveness());
    }

    /**
     * @return iterable<string, array{Exposure, Exposure, Exposure}>
     */
    public static function pairs(): iterable
    {
        $order = [Exposure::Exposed, Exposure::NeedsConfirmation, Exposure::ReadOnlyBlocked, Exposure::Denied];
        foreach ($order as $i => $one) {
            foreach ($order as $j => $other) {
                yield $one->value . ' and ' . $other->value => [$one, $other, $order[max($i, $j)]];
            }
        }
    }

    #[DataProvider('pairs')]
    public function testTheStricterOfTwoIsTheOneHigherInThePrecedenceWhicheverAsks(
        Exposure $one,
        Exposure $other,
        Exposure $stricter
    ): void {
        self::assertSame($stricter, $one->stricter($other));
        self::assertSame($stricter, $other->stricter($one));
    }

    public function testAnExposureThatRefusesCallsHasADefaultReasonAndTheOthersNone(): void
    {
        self::assertSame('deny_list', Exposure::Denied->defaultDenialReason());
        self::assertSame('read_only_mode', Exposure::ReadOnlyBlocked->defaultDenialReason());
        self::assertSame('', Exposure::NeedsConfirmation->defaultDenialReason());
        self::assertSame('', Exposure::Exposed->defaultDenialReason());
    }

    public function testExposureValuesAreTheWordsClientsSee(): void
    {
        self::assertSame(
            ['exposed', 'needs_confirmation', 'read_only_blocked', 'denied'],
            array_column(Exposure::cases(), 'value')
        );
    }
}
