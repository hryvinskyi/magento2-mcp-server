<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\Exception\JsonRpcErrorException;

/**
 * One MCP tool a client sees; the tools are the di.xml pool `tools`, shared by `tools/list` and `tools/call`
 *
 * The store's abilities are never tools themselves, since there are hundreds of them: a client finds and calls them
 * through a few meta-tools.
 */
interface ToolInterface
{
    /**
     * The name a client calls the tool by
     *
     * @return string
     */
    public function getName(): string;

    /**
     * What the tool does, for the model that decides to call it
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * JSON Schema of the tool's arguments; an object that may be empty is an \stdClass
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * MCP tool annotations (`title`, `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`)
     *
     * @return array<string, mixed>
     */
    public function getAnnotations(): array;

    /**
     * Run the tool and give its MCP tool result (`content`, `structuredContent`, `isError`)
     *
     * @param array<string,mixed> $arguments
     * @param CallContext $context
     * @return array<string, mixed>
     * @throws InvalidToolArgumentException When an argument cannot be taken; answered as a tool error
     * @throws JsonRpcErrorException When the call is answered with a JSON-RPC error instead of a result
     */
    public function call(array $arguments, CallContext $context): array;
}
