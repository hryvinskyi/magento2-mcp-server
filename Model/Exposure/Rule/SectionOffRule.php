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
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\ReasonedExposureRuleInterface;
use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\Section\AbilitySections;

/**
 * Denies every ability of a section the admin switched off, with the reason `section_off`
 *
 * An ability that belongs to several sections is denied as soon as one of them is off.
 */
class SectionOffRule implements ReasonedExposureRuleInterface
{
    /**
     * @param AbilitySections $sections
     * @param SectionSwitchesInterface $switches
     */
    public function __construct(
        private readonly AbilitySections $sections,
        private readonly SectionSwitchesInterface $switches
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        if ($this->switches->off() === []) {
            return null;
        }
        foreach ($this->sections->of($ability) as $section) {
            if ($this->switches->isOff($section)) {
                return Exposure::Denied;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function denialReason(): string
    {
        return InvocationResultInterface::DENIAL_SECTION_OFF;
    }
}
