<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Instruction;

/**
 * The instructions of the server: the parts of the di.xml pool `parts`, in their order, each that has something to say
 */
class ServerInstructions implements InstructionInterface
{
    /**
     * @param array<string,InstructionInterface> $parts
     */
    public function __construct(
        private readonly array $parts = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function text(): string
    {
        $texts = [];
        foreach ($this->parts as $part) {
            $text = $part->text();
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return implode(' ', $texts);
    }
}
