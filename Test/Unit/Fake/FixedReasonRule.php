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
use Hryvinskyi\McpServer\Api\ReasonedExposureRuleInterface;

/**
 * An exposure rule that gives the same answer for every ability and names its own denial reason
 */
class FixedReasonRule implements ReasonedExposureRuleInterface
{
    /**
     * @param Exposure|null $answer
     * @param string $reason
     */
    public function __construct(
        private readonly ?Exposure $answer,
        private readonly string $reason
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        return $this->answer;
    }

    /**
     * @inheritDoc
     */
    public function denialReason(): string
    {
        return $this->reason;
    }
}
