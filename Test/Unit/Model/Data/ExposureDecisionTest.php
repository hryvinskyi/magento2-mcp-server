<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Data;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\Data\ExposureDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An exposure decision names a reason exactly when its exposure refuses the call
 */
class ExposureDecisionTest extends TestCase
{
    public function testARefusingExposureKeepsItsReason(): void
    {
        $decision = new ExposureDecision(Exposure::Denied, 'anonymous_ability');

        self::assertSame(Exposure::Denied, $decision->getExposure());
        self::assertSame('anonymous_ability', $decision->getDenialReason());
    }

    public function testAnExposureThatLetsTheCallThroughHasNoReason(): void
    {
        $decision = new ExposureDecision(Exposure::NeedsConfirmation);

        self::assertSame(Exposure::NeedsConfirmation, $decision->getExposure());
        self::assertSame('', $decision->getDenialReason());
    }

    /**
     * @return array<string, array{Exposure, string}>
     */
    public static function invalid(): array
    {
        return [
            'denied without a reason' => [Exposure::Denied, ''],
            'blocked without a reason' => [Exposure::ReadOnlyBlocked, ''],
            'a reason that is not a code' => [Exposure::Denied, 'Deny List'],
            'a reason with a trailing underscore' => [Exposure::Denied, 'deny_'],
            'exposed with a reason' => [Exposure::Exposed, 'deny_list'],
            'needing confirmation with a reason' => [Exposure::NeedsConfirmation, 'deny_list'],
        ];
    }

    #[DataProvider('invalid')]
    public function testAReasonThatContradictsTheExposureIsRefused(Exposure $exposure, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExposureDecision($exposure, $reason);
    }
}
