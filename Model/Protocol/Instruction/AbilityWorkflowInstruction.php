<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Instruction;

/**
 * How abilities are found, read and run through the three tools
 */
class AbilityWorkflowInstruction implements InstructionInterface
{
    /**
     * @inheritDoc
     */
    public function text(): string
    {
        return 'Find the operations of this Magento store with discover_abilities, then read the arguments of the one '
            . 'you need with get_ability_info. Run it with execute_ability, which the store may deny or ask you to '
            . 'confirm.';
    }
}
