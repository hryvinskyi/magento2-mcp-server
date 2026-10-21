<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

/**
 * The MCP tools a client sees, from the di.xml pool `tools`, so listing them and calling one read the same pool
 *
 * A tool is found by the name it gives itself; the pool's keys only let another module add, replace or drop a tool.
 */
class ToolRegistry
{
    /**
     * @param array<array-key,ToolInterface|null> $tools Tools keyed by any name; null drops one
     */
    public function __construct(
        private readonly array $tools = []
    ) {
    }

    /**
     * Every tool, in the pool's order
     *
     * @return list<ToolInterface>
     */
    public function all(): array
    {
        return array_values(array_filter($this->tools, static fn (?ToolInterface $tool): bool => $tool !== null));
    }

    /**
     * The name of every tool, in the pool's order
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $this->all());
    }

    /**
     * The tool with this name, or null when no tool has it
     *
     * @param string $name
     * @return ToolInterface|null
     */
    public function find(string $name): ?ToolInterface
    {
        foreach ($this->all() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
