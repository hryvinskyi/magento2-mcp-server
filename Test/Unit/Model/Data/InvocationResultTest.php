<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\InvocationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvocationResultTest extends TestCase
{
    public function testASucceededResultCarriesItsValueOnACopy(): void
    {
        $ability = new Ability('a.b.c', 'Title', 'Magento_Catalog', 'webapi', 'GET /V1/x', AbilityAccess::Read);
        $result = new InvocationResult(InvocationOutcome::Succeeded, 12.5, $ability);
        $value = ['items' => [['instance' => 'x', 'argument' => 'db']]];

        $withValue = $result->withValue($value);

        self::assertNotSame($result, $withValue);
        self::assertNull($result->getValue());
        self::assertSame($value, $withValue->getValue());
        self::assertSame(InvocationOutcome::Succeeded, $withValue->getOutcome());
        self::assertSame(12.5, $withValue->getDurationMs());
        self::assertSame($ability, $withValue->getAbility());
        self::assertSame('', $withValue->getMessage());
        self::assertSame('', $withValue->getDenialReason());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function denialReasons(): array
    {
        return [
            'deny-list' => [InvocationResultInterface::DENIAL_DENY_LIST],
            'read-only mode' => [InvocationResultInterface::DENIAL_READ_ONLY_MODE],
            'acl' => [InvocationResultInterface::DENIAL_ACL],
        ];
    }

    #[DataProvider('denialReasons')]
    public function testADeniedResultNamesItsReason(string $reason): void
    {
        $result = new InvocationResult(InvocationOutcome::Denied, 0.0, null, 'Refused.', $reason);

        self::assertSame($reason, $result->getDenialReason());
        self::assertSame('Refused.', $result->getMessage());
        self::assertNull($result->getAbility());
    }

    public function testAFailedResultSaysWhatWentWrong(): void
    {
        $result = new InvocationResult(InvocationOutcome::Failed, 3.0, null, 'The product does not exist.');

        self::assertSame('The product does not exist.', $result->getMessage());
    }

    public function testAResultWaitingForConfirmationNeedsNoMessage(): void
    {
        $result = new InvocationResult(InvocationOutcome::NeedsConfirmation, 0.4);

        self::assertSame(InvocationOutcome::NeedsConfirmation, $result->getOutcome());
    }

    /**
     * @return array<string, array{InvocationOutcome, float, string, string}>
     */
    public static function contradictions(): array
    {
        return [
            'denied without reason' => [InvocationOutcome::Denied, 1.0, 'Refused.', ''],
            'denied with a malformed reason' => [InvocationOutcome::Denied, 1.0, 'Refused.', 'Deny List'],
            'denied with a trailing newline' => [InvocationOutcome::Denied, 1.0, 'Refused.', "acl\n"],
            'succeeded with a reason' => [InvocationOutcome::Succeeded, 1.0, '', 'acl'],
            'failed with a reason' => [InvocationOutcome::Failed, 1.0, 'Broken.', 'deny_list'],
            'failed without message' => [InvocationOutcome::Failed, 1.0, ' ', ''],
            'negative duration' => [InvocationOutcome::Succeeded, -0.1, '', ''],
            'infinite duration' => [InvocationOutcome::Succeeded, INF, '', ''],
            'duration not a number' => [InvocationOutcome::Succeeded, NAN, '', ''],
        ];
    }

    #[DataProvider('contradictions')]
    public function testRefusesValuesThatContradictTheOutcome(
        InvocationOutcome $outcome,
        float $durationMs,
        string $message,
        string $denialReason
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new InvocationResult($outcome, $durationMs, null, $message, $denialReason);
    }

    /**
     * @return array<string, array{InvocationResult}>
     */
    public static function resultsWithoutValue(): array
    {
        return [
            'failed' => [new InvocationResult(InvocationOutcome::Failed, 1.0, null, 'Broken.')],
            'denied' => [new InvocationResult(InvocationOutcome::Denied, 1.0, null, '', 'acl')],
            'needs confirmation' => [new InvocationResult(InvocationOutcome::NeedsConfirmation, 1.0)],
        ];
    }

    #[DataProvider('resultsWithoutValue')]
    public function testOnlyASucceededResultTakesAValue(InvocationResult $result): void
    {
        $this->expectException(\LogicException::class);

        $result->withValue(['id' => 1]);
    }
}
