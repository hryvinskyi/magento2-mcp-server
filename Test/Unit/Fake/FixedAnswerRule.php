<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\ExposureRuleInterface;

/**
 * An exposure rule that gives the same answer for every ability
 */
class FixedAnswerRule implements ExposureRuleInterface
{
    /**
     * @param Exposure|null $answer
     */
    public function __construct(
        private readonly ?Exposure $answer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        return $this->answer;
    }
}
