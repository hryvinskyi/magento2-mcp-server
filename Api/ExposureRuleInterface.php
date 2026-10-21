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

/**
 * One restriction the exposure policy weighs, such as the deny-list or read-only mode
 *
 * A rule may only restrict. The policy asks every rule and keeps the strictest answer (Exposure::stricter()), so a
 * rule that answers Exposure::Exposed changes nothing, and no rule can lift what another rule decided. Rules join
 * the policy through the `rules` argument of Hryvinskyi\McpServer\Model\Exposure\ExposurePolicy in di.xml; their
 * order there does not matter.
 *
 * @api
 */
interface ExposureRuleInterface
{
    /**
     * The restriction this rule puts on the ability, or null when it has none
     *
     * @param AbilityInterface $ability
     * @return Exposure|null
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure;
}
