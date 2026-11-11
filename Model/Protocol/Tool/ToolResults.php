<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;

/**
 * Builds MCP tool results
 *
 * A successful result carries its value once, as JSON text in `content`, which every client reads. It carries no
 * `structuredContent`: the tools declare no output schema, and a second copy of the value would double every answer.
 * Notes for the model may follow the value in `content`, each as a text of its own. An error result carries a message
 * the model reads to correct its call.
 */
class ToolResults
{
    /**
     * @param JsonEncoder $encoder
     */
    public function __construct(
        private readonly JsonEncoder $encoder
    ) {
    }

    /**
     * The result of a call that worked
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    public function success(mixed $value): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $this->encoder->encode($value)]],
            'isError' => false,
        ];
    }

    /**
     * The tool result with these notes for the model after its content, each as a text of its own
     *
     * @param array<string,mixed> $result
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    public function withNotes(array $result, array $notes): array
    {
        if ($notes === []) {
            return $result;
        }

        $content = is_array($result['content'] ?? null) ? array_values($result['content']) : [];
        foreach ($notes as $note) {
            $content[] = ['type' => 'text', 'text' => $note];
        }
        $result['content'] = $content;

        return $result;
    }

    /**
     * The error result of a call that names no ability, pointing the model at the tool that finds them
     *
     * @param string $name
     * @return array<string, mixed>
     */
    public function unknownAbility(string $name): array
    {
        return $this->error(
            __('No ability is named "%1". Find ability names with discover_abilities.', $name)->render()
        );
    }

    /**
     * The result of a call that went wrong in a way the model can correct or report
     *
     * @param string $message
     * @return array<string, mixed>
     */
    public function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
