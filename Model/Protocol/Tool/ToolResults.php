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
 * A successful result carries its value twice: as JSON text in `content`, for clients that only read text, and as
 * `structuredContent`, which must be a JSON object, so a scalar, null or a list is wrapped as `{"result": …}`. An error
 * result carries a message the model reads to correct its call.
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
        $structured = is_array($value) && $value !== [] && !array_is_list($value) ? $value : ['result' => $value];

        return [
            'content' => [['type' => 'text', 'text' => $this->encoder->encode($structured)]],
            'structuredContent' => $structured,
            'isError' => false,
        ];
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
