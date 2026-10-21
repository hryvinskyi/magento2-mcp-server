<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Catalog;

use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Finds the source that offers an ability, the one that may run it
 *
 * Kept off the published catalog on purpose: holding a source lets code run an ability without the exposure, ACL and
 * confirmation checks, so only the invoker asks for one.
 */
interface AbilitySourceResolverInterface
{
    /**
     * The source that listed this ability
     *
     * @param AbilityInterface $ability
     * @return AbilitySourceInterface
     * @throws NoSuchEntityException When no source offers an ability of that name and source
     */
    public function sourceOf(AbilityInterface $ability): AbilitySourceInterface;
}
