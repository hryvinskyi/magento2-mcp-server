<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\Section\SectionSwitches;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * Section switches whose store refuses every write, as a lost database connection does
 *
 * Reads come from the real table, so the sections are listed as they are stored; switching a section off or on
 * throws CouldNotSaveException carrying the storage error as its previous exception, and changes nothing.
 */
class FailingSectionSwitches implements SectionSwitchesInterface
{
    public const STORAGE_ERROR = 'The test refused to store the switch.';

    /**
     * @param SectionSwitches $stored
     */
    public function __construct(
        private readonly SectionSwitches $stored
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isOff(string $section): bool
    {
        return $this->stored->isOff($section);
    }

    /**
     * @inheritDoc
     */
    public function off(): array
    {
        return $this->stored->off();
    }

    /**
     * @inheritDoc
     */
    public function switchOff(string $section): void
    {
        throw new CouldNotSaveException(
            __('The section "%1" could not be switched off.', $section),
            new \RuntimeException(self::STORAGE_ERROR)
        );
    }

    /**
     * @inheritDoc
     */
    public function switchOn(string $section): void
    {
        throw new CouldNotSaveException(
            __('The section "%1" could not be switched on.', $section),
            new \RuntimeException(self::STORAGE_ERROR)
        );
    }
}
