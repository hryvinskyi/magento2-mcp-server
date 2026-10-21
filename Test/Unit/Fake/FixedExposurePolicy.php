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
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterface;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Model\Data\ExposureDecision;

/**
 * An exposure policy that gives every ability the same exposure, with a chosen denial reason or the exposure's
 * default one, or throws the given failure
 */
class FixedExposurePolicy implements ExposurePolicyInterface
{
    /**
     * @param Exposure $exposure
     * @param \Throwable|null $failure
     * @param string|null $denialReason Reason of a refusing exposure; null for the exposure's default
     */
    public function __construct(
        private readonly Exposure $exposure,
        private readonly ?\Throwable $failure = null,
        private readonly ?string $denialReason = null
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
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new ExposureDecision(
            $this->exposure,
            $this->exposure->allowsCall() ? '' : ($this->denialReason ?? $this->exposure->defaultDenialReason())
        );
    }
}
