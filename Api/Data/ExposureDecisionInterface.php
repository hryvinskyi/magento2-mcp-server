<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * The exposure policy's answer for one ability: its exposure and, when a call is refused, why
 *
 * An exposure that refuses every call (denied, read-only blocked) always comes with a denial reason, and one that
 * lets a call through never does. Create one through ExposureDecisionInterfaceFactory.
 *
 * @api
 */
interface ExposureDecisionInterface
{
    /**
     * The ability's exposure
     *
     * @return Exposure
     */
    public function getExposure(): Exposure;

    /**
     * Why a call is refused (`deny_list`, `read_only_mode`, `anonymous_ability` or another rule's code), or ''
     *
     * @return string
     */
    public function getDenialReason(): string;
}
