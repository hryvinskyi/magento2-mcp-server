<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Instruction;

/**
 * One part of the instructions `initialize` gives the client: what the model should know before its first call
 */
interface InstructionInterface
{
    /**
     * The sentences of this part, or '' when the store gives the model nothing to know about it
     *
     * @return string
     */
    public function text(): string;
}
