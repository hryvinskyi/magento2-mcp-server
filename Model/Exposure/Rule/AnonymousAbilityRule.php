<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Exposure\Rule;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\Data\InvocationResultInterface;
use Hryvinskyi\McpServer\Api\ReasonedExposureRuleInterface;

/**
 * Denies every ability that anyone may call, unless the merchant exposes such abilities
 *
 * An ability whose only ACL resource is `anonymous` is meant for guests of the storefront. Called through Magento's
 * REST API it is guarded per route, by CAPTCHA and rate limits; called through MCP those guards ran once, for the MCP
 * request, never for the ability. So these abilities stay denied, with the reason `anonymous_ability`, until the
 * setting that exposes them is switched on.
 *
 * The resources are compared as a set: a list that repeats `anonymous`, or keeps it under a key of its own, still
 * names nothing but `anonymous`, and Magento's guest authorization allows it all the same.
 */
class AnonymousAbilityRule implements ReasonedExposureRuleInterface
{
    private const ANONYMOUS_RESOURCE = 'anonymous';

    /**
     * @param ConfigInterface $config
     */
    public function __construct(
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exposureOf(AbilityInterface $ability): ?Exposure
    {
        if (array_values(array_unique($ability->getAclResources())) !== [self::ANONYMOUS_RESOURCE]) {
            return null;
        }

        return $this->config->isAnonymousExposed() ? null : Exposure::Denied;
    }

    /**
     * @inheritDoc
     */
    public function denialReason(): string
    {
        return InvocationResultInterface::DENIAL_ANONYMOUS_ABILITY;
    }
}
