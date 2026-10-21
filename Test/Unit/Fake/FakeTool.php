<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Tool\ToolInterface;

/**
 * A tool with no parameters that runs what the test gives it, or echoes its arguments
 */
class FakeTool implements ToolInterface
{
    /**
     * Arguments of every call, in order
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * @param string $name
     * @param (\Closure(array<string,mixed>,CallContext): array<string,mixed>)|null $behaviour
     */
    public function __construct(
        private readonly string $name = 'fake_tool',
        private readonly ?\Closure $behaviour = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'A tool for tests.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    /**
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        return ['readOnlyHint' => true];
    }

    /**
     * @inheritDoc
     */
    public function call(array $arguments, CallContext $context): array
    {
        $this->calls[] = $arguments;

        return $this->behaviour === null
            ? ['content' => [], 'structuredContent' => $arguments, 'isError' => false]
            : ($this->behaviour)($arguments, $context);
    }
}
