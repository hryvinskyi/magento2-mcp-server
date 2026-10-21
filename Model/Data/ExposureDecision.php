<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\ExposureDecisionInterface;

/**
 * Immutable exposure decision, created through ExposureDecisionInterfaceFactory
 *
 * The constructor refuses an exposure that refuses calls without a reason in lowercase snake case, and a reason on
 * an exposure that lets calls through.
 */
class ExposureDecision implements ExposureDecisionInterface
{
    private const DENIAL_REASON_PATTERN = '/^[a-z]+(?:_[a-z]+)*$/D';

    /**
     * @param Exposure $exposure
     * @param string $denialReason
     * @throws \InvalidArgumentException When the reason contradicts the exposure
     */
    public function __construct(
        private readonly Exposure $exposure,
        private readonly string $denialReason = ''
    ) {
        if (!$exposure->allowsCall() && preg_match(self::DENIAL_REASON_PATTERN, $denialReason) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A %s exposure names its denial reason as a lowercase code, not "%s".',
                $exposure->value,
                $denialReason
            ));
        }

        if ($exposure->allowsCall() && $denialReason !== '') {
            throw new \InvalidArgumentException(
                sprintf('Only an exposure that refuses calls has a denial reason, not %s.', $exposure->value)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getExposure(): Exposure
    {
        return $this->exposure;
    }

    /**
     * @inheritDoc
     */
    public function getDenialReason(): string
    {
        return $this->denialReason;
    }
}
