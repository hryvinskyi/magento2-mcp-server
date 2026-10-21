<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Exposure;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterface;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterfaceFactory;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Api\ExposureRuleInterface;
use Hryvinskyi\McpServer\Api\ReasonedExposureRuleInterface;

/**
 * Weighs every exposure rule and keeps the strictest answer
 *
 * An ability no rule restricts is exposed. Because the strictest answer wins, the precedence (the deny-list, then
 * read-only mode, then a required confirmation, then exposed) comes from Exposure::restrictiveness() alone: it holds
 * whatever order di.xml lists the rules in, and a rule added by another module can tighten an ability's exposure but
 * never lift a deny-list or read-only answer.
 *
 * The denial reason is chosen without regard to order as well. Every rule that gives the strictest answer offers its
 * reason: its own when it names one (ReasonedExposureRuleInterface), else the answer's default. The answer's default
 * reason wins when it is among them, so an ability the merchant deny-listed reads as deny-listed whatever other rule
 * also denies it; otherwise the alphabetically first reason wins.
 */
class ExposurePolicy implements ExposurePolicyInterface
{
    /**
     * @param ExposureDecisionInterfaceFactory $decisionFactory
     * @param ExposureRuleInterface[] $rules
     */
    public function __construct(
        private readonly ExposureDecisionInterfaceFactory $decisionFactory,
        private readonly array $rules = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): Exposure
    {
        return $this->decisionOf($ability)->getExposure();
    }

    /**
     * @inheritDoc
     */
    public function decisionOf(AbilityInterface $ability): ExposureDecisionInterface
    {
        $exposure = Exposure::Exposed;
        $reasons = [];
        foreach ($this->rules as $rule) {
            $answer = $rule->exposureOf($ability);
            if ($answer === null) {
                continue;
            }

            $stricter = $exposure->stricter($answer);
            if ($stricter !== $exposure) {
                $exposure = $stricter;
                $reasons = [];
            }
            if ($answer === $exposure) {
                $reasons[] = $this->reasonOf($rule, $answer);
            }
        }

        return $this->decisionFactory->create([
            'exposure' => $exposure,
            'denialReason' => $exposure->allowsCall() ? '' : $this->reasonAmong($exposure, $reasons),
        ]);
    }

    /**
     * The reason a rule gives for its answer: its own when it names one, else the answer's default
     *
     * @param ExposureRuleInterface $rule
     * @param Exposure $answer
     * @return string
     */
    private function reasonOf(ExposureRuleInterface $rule, Exposure $answer): string
    {
        return $rule instanceof ReasonedExposureRuleInterface
            ? $rule->denialReason()
            : $answer->defaultDenialReason();
    }

    /**
     * The reason reported for an exposure several rules may have given: its default when offered, else the first
     *
     * @param Exposure $exposure
     * @param list<string> $reasons
     * @return string
     */
    private function reasonAmong(Exposure $exposure, array $reasons): string
    {
        $default = $exposure->defaultDenialReason();
        if ($reasons === [] || in_array($default, $reasons, true)) {
            return $default;
        }

        sort($reasons, SORT_STRING);

        return $reasons[0];
    }
}
