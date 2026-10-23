<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;

/**
 * Section switches held in memory
 */
class InMemorySectionSwitches implements SectionSwitchesInterface
{
    /**
     * @param list<string> $offSections
     */
    public function __construct(
        private array $offSections = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isOff(string $section): bool
    {
        return in_array($section, $this->offSections, true);
    }

    /**
     * @inheritDoc
     */
    public function off(): array
    {
        return $this->offSections;
    }

    /**
     * @inheritDoc
     */
    public function switchOff(string $section): void
    {
        $this->offSections = array_values(array_unique([...$this->offSections, $section]));
    }

    /**
     * @inheritDoc
     */
    public function switchOn(string $section): void
    {
        $this->offSections = array_values(array_diff($this->offSections, [$section]));
    }
}
