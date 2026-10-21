<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

/**
 * An exposure rule that names the reason a call it refuses is denied for
 *
 * A rule that implements only ExposureRuleInterface is reported with the default reason of its answer
 * (Exposure::defaultDenialReason()): `deny_list` for a denied ability, `read_only_mode` for a blocked one. A rule
 * with a restriction of its own implements this interface and names it, such as `anonymous_ability`.
 *
 * One rule stands for one restriction, so its reason does not depend on the ability. How the policy picks the reason
 * when several rules restrict the same ability is told by ExposurePolicyInterface::decisionOf().
 *
 * @api
 */
interface ReasonedExposureRuleInterface extends ExposureRuleInterface
{
    /**
     * The denial reason of a call this rule refuses, as a lowercase snake-case code
     *
     * @return string
     */
    public function denialReason(): string;
}
