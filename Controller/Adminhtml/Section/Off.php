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
 * Switches a section off: no client may call its abilities
 */
class Off extends SectionSwitch
{
    /**
     * @inheritDoc
     */
    protected function switchSection(string $code): void
    {
        $this->switches->switchOff($code);
    }

    /**
     * @inheritDoc
     */
    protected function doneMessage(string $label): Phrase
    {
        return __('The section %1 is switched off: no client may call its abilities.', $label);
    }
}
