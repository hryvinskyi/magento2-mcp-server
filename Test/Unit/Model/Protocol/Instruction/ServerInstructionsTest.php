<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol\Instruction;

use Hryvinskyi\McpServer\Model\Protocol\Instruction\InstructionInterface;
use Hryvinskyi\McpServer\Model\Protocol\Instruction\ServerInstructions;
use PHPUnit\Framework\TestCase;

/**
 * How the parts of the server's instructions are joined
 */
class ServerInstructionsTest extends TestCase
{
    public function testThePartsAreJoinedInTheirOrderAndAPartWithNothingToSayIsLeftOut(): void
    {
        $instructions = new ServerInstructions([
            'first' => $this->part('First.'),
            'silent' => $this->part(''),
            'second' => $this->part('Second.'),
        ]);

        self::assertSame('First. Second.', $instructions->text());
    }

    public function testNoPartsSayNothing(): void
    {
        self::assertSame('', (new ServerInstructions())->text());
    }

    /**
     * @param string $text
     * @return InstructionInterface
     */
    private function part(string $text): InstructionInterface
    {
        return new class ($text) implements InstructionInterface {
            /**
             * @param string $text
             */
            public function __construct(private readonly string $text)
            {
            }

            /**
             * @inheritDoc
             */
            public function text(): string
            {
                return $this->text;
            }
        };
    }
}
