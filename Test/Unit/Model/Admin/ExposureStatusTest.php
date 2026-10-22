<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin;

use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Admin\ExposureStatus;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type AbilityRow from AbilityRows
 */
class ExposureStatusTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, list<string|null>}>
     */
    public static function rows(): array
    {
        return [
            'exposed: may be denied here' => ['exposed', '', false, ['Exposed', '', 'exposed', 'deny']],
            'needs confirmation: may be denied here' => [
                'needs_confirmation',
                '',
                false,
                ['Needs confirmation', 'A client confirms each call before it runs.', 'confirm', 'deny'],
            ],
            'read-only mode: nothing to change here' => [
                'read_only_blocked',
                'read_only_mode',
                false,
                [
                    'Blocked: read-only mode',
                    'Read-only mode is on, and this ability does not only read.',
                    'blocked',
                    null,
                ],
            ],
            'denied on this page: may be allowed again' => [
                'denied',
                'deny_list',
                true,
                ['Denied: deny-list', 'Put on the deny-list on this page.', 'denied', 'allow'],
            ],
            'denied by the module: nothing to change here' => [
                'denied',
                'deny_list',
                false,
                [
                    'Denied: built-in deny-list',
                    'The module keeps it from every client: it issues tokens, handles passwords or acts as another '
                        . 'account.',
                    'denied',
                    null,
                ],
            ],
            'open to anyone' => [
                'denied',
                'anonymous_ability',
                false,
                [
                    'Denied: open to anyone',
                    'Its route needs no login, and Expose Anonymous Abilities is off.',
                    'denied',
                    null,
                ],
            ],
            'another module\'s rule' => [
                'denied',
                'quiet_hours',
                false,
                ['Denied by a rule', 'Rule: quiet_hours', 'denied', null],
            ],
        ];
    }

    /**
     * @dataProvider rows
     * @param string $exposure
     * @param string $reason
     * @param bool $deniedHere
     * @param list<string|null> $expected Label, note, tone, change
     */
    public function testTheStatusSaysWhatDecidedTheExposureAndWhatTheRowOffers(
        string $exposure,
        string $reason,
        bool $deniedHere,
        array $expected
    ): void {
        $status = (new ExposureStatus())->of([
            'name' => 'catalog.product_repository.save',
            'title' => 'Save a product',
            'source' => 'webapi',
            'category' => 'Magento_Catalog',
            'access' => 'write',
            'aclResources' => ['Magento_Catalog::products'],
            'exposure' => $exposure,
            'reason' => $reason,
            'deniedHere' => $deniedHere,
            'confirmHere' => false,
        ]);

        self::assertSame($expected, [$status['label'], $status['note'], $status['tone'], $status['change']]);
    }
}
