<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;

/**
 * An ability's exposure as the catalog grid states it: a label, a line saying why, and the change a row offers
 *
 * A denied ability says which deny-list keeps it: the one the merchant manages on this page, the module's built-in
 * one, or the rule that keeps abilities anyone may call closed. A row offers to take an ability off the deny-list the
 * merchant put it on, and to put an exposed one on it. An ability the module keeps denied, or that read-only mode
 * blocks, offers nothing: its state does not depend on this page.
 *
 * @phpstan-import-type AbilityRow from AbilityRows
 * @phpstan-type Status array{code: string, label: string, note: string, tone: string, change: string|null}
 */
class ExposureStatus
{
    public const DENY = 'deny';
    public const ALLOW = 'allow';

    /**
     * The status of one row
     *
     * @param AbilityRow $row
     * @return Status
     */
    public function of(array $row): array
    {
        $code = $this->codeOf($row);

        return [
            'code' => $code,
            'label' => $this->labelOf($code),
            'note' => $this->noteOf($code, $row['reason']),
            'tone' => $this->toneOf($row['exposure']),
            'change' => $this->changeOf($code),
        ];
    }

    /**
     * What decided the row's exposure, as one code
     *
     * @param AbilityRow $row
     * @return string
     */
    private function codeOf(array $row): string
    {
        if ($row['deniedHere']) {
            return 'denied_here';
        }

        return $row['exposure'] === Exposure::Denied->value ? 'denied_' . $row['reason'] : $row['exposure'];
    }

    /**
     * The label of a status
     *
     * @param string $code
     * @return string
     */
    private function labelOf(string $code): string
    {
        return (string) match ($code) {
            Exposure::Exposed->value => __('Exposed'),
            Exposure::NeedsConfirmation->value => __('Needs confirmation'),
            Exposure::ReadOnlyBlocked->value => __('Blocked: read-only mode'),
            'denied_here' => __('Denied: deny-list'),
            'denied_' . InvocationResultInterface::DENIAL_DENY_LIST => __('Denied: built-in deny-list'),
            'denied_' . InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY => __('Denied: open to anyone'),
            default => __('Denied by a rule'),
        };
    }

    /**
     * The line that says why
     *
     * @param string $code
     * @param string $reason
     * @return string
     */
    private function noteOf(string $code, string $reason): string
    {
        return (string) match ($code) {
            Exposure::Exposed->value => '',
            Exposure::NeedsConfirmation->value => __('A client confirms each call before it runs.'),
            Exposure::ReadOnlyBlocked->value => __('Read-only mode is on, and this ability does not only read.'),
            'denied_here' => __('Put on the deny-list on this page.'),
            'denied_' . InvocationResultInterface::DENIAL_DENY_LIST => __(
                'The module keeps it from every client: it issues tokens, handles passwords or acts as another account.'
            ),
            'denied_' . InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY
                => __('Its route needs no login, and Expose Anonymous Abilities is off.'),
            default => __('Rule: %1', $reason),
        };
    }

    /**
     * The colour the status is drawn in
     *
     * @param string $exposure
     * @return string
     */
    private function toneOf(string $exposure): string
    {
        return match ($exposure) {
            Exposure::Exposed->value => 'exposed',
            Exposure::NeedsConfirmation->value => 'confirm',
            Exposure::ReadOnlyBlocked->value => 'blocked',
            default => 'denied',
        };
    }

    /**
     * The change a row offers, or null
     *
     * @param string $code
     * @return string|null
     */
    private function changeOf(string $code): ?string
    {
        return match ($code) {
            'denied_here' => self::ALLOW,
            Exposure::Exposed->value, Exposure::NeedsConfirmation->value => self::DENY,
            default => null,
        };
    }
}
