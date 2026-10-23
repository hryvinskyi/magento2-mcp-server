<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Section;

use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\ResourceModel\NameSet;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * The sections switched off, kept in the table hryvinskyi_mcp_section_off
 */
class SectionSwitches implements SectionSwitchesInterface
{
    /**
     * @param NameSet $off
     */
    public function __construct(
        private readonly NameSet $off
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isOff(string $section): bool
    {
        return $this->off->has($section);
    }

    /**
     * @inheritDoc
     */
    public function off(): array
    {
        return $this->off->all();
    }

    /**
     * @inheritDoc
     */
    public function switchOff(string $section): void
    {
        try {
            $this->off->add([$section]);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('The section "%1" could not be switched off.', $section), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function switchOn(string $section): void
    {
        try {
            $this->off->remove([$section]);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('The section "%1" could not be switched on.', $section), $e);
        }
    }
}
