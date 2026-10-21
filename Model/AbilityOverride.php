<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model;

use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Model\ResourceModel\AbilityOverride as AbilityOverrideResource;
use Magento\Framework\Model\AbstractModel;

/**
 * A stored per-ability override, as a row of the override table
 *
 * The flags are stored as 0 and 1.
 */
class AbilityOverride extends AbstractModel implements AbilityOverrideInterface
{
    /**
     * Bind the model to its resource
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AbilityOverrideResource::class);
    }

    /**
     * @inheritDoc
     */
    public function getAbility(): string
    {
        $ability = $this->getData(self::ABILITY);

        return is_string($ability) ? $ability : '';
    }

    /**
     * @inheritDoc
     */
    public function setAbility(string $ability): AbilityOverrideInterface
    {
        return $this->setData(self::ABILITY, $ability);
    }

    /**
     * @inheritDoc
     */
    public function isDenied(): bool
    {
        return (bool) $this->getData(self::DENY);
    }

    /**
     * @inheritDoc
     */
    public function setDenied(bool $denied): AbilityOverrideInterface
    {
        return $this->setData(self::DENY, (int) $denied);
    }

    /**
     * @inheritDoc
     */
    public function needsConfirmation(): bool
    {
        return (bool) $this->getData(self::CONFIRM);
    }

    /**
     * @inheritDoc
     */
    public function setNeedsConfirmation(bool $needsConfirmation): AbilityOverrideInterface
    {
        return $this->setData(self::CONFIRM, (int) $needsConfirmation);
    }
}
