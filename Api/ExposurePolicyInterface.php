<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterface;

/**
 * Decides whether a client may call an ability, whoever the caller is
 *
 * The precedence is: the deny-list, then read-only mode (for abilities that do not only read), then a required
 * confirmation, else the ability is exposed. The caller's ACL is a separate check.
 *
 * @api
 */
interface ExposurePolicyInterface
{
    /**
     * The exposure of this ability under the current settings and overrides
     *
     * @param AbilityInterface $ability
     * @return Exposure
     */
    public function exposureOf(AbilityInterface $ability): Exposure;

    /**
     * The exposure of this ability, with the reason a call is refused for when it is
     *
     * The reason is the one the strictest rule names. When several rules give the strictest answer, the answer's
     * default reason (Exposure::defaultDenialReason()) wins if one of them names it, else the alphabetically first
     * of their reasons: the decision never depends on the order the rules are listed in.
     *
     * @param AbilityInterface $ability
     * @return ExposureDecisionInterface
     */
    public function decisionOf(AbilityInterface $ability): ExposureDecisionInterface;
}
