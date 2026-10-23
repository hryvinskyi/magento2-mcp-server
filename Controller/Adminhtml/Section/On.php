<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Controller\Adminhtml\Section;

use Magento\Framework\Phrase;

/**
 * Switches a section on again: its abilities are exposed as the other rules decide
 */
class On extends SectionSwitch
{
    /**
     * @inheritDoc
     */
    protected function switchSection(string $code): void
    {
        $this->switches->switchOn($code);
    }

    /**
     * @inheritDoc
     */
    protected function doneMessage(string $label): Phrase
    {
        return __('The section %1 is switched on.', $label);
    }
}
