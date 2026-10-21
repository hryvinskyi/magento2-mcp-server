<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Exposure\Rule;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\ExposureRuleInterface;

/**
 * Denies the abilities no client should ever call, whatever the merchant's overrides say
 *
 * The list holds ability-name patterns: shell globs matched with fnmatch() against the whole name, where `*` also
 * matches the dots between the name's parts (`integration.*` covers every ability of the integration module). They
 * come from the `patterns` argument in di.xml, keyed by the reason each pattern is there, so another module can add
 * a pattern under a key of its own or drop one by setting its key to null.
 *
 * A denial from this list is reported as a deny-list denial, like a stored override; matches() tells the two apart
 * for screens that show why an ability is denied.
 */
class BuiltInDenyListRule implements ExposureRuleInterface
{
    /**
     * @param array<string,string|null> $patterns Ability-name globs keyed by reason; null drops a pattern
     */
    public function __construct(
        private readonly array $patterns = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        return $this->matches($ability->getName()) ? Exposure::Denied : null;
    }

    /**
     * Whether the ability name matches a pattern of the built-in deny-list
     *
     * @param string $name
     * @return bool
     */
    public function matches(string $name): bool
    {
        foreach ($this->patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
