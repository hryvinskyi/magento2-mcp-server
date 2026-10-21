<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * Writes every JSON body and every JSON text the server sends, one way
 *
 * - Broken UTF-8 in service data is replaced with U+FFFD instead of failing the whole answer.
 * - Slashes and non-ASCII characters are written as they are, and a float keeps its fraction (`1.0`, not `1`).
 * - Any other failure throws instead of answering `false`.
 *
 * PHP writes an empty array as `[]`, while MCP clients check `result`, `capabilities`, `inputSchema.properties` and
 * an open schema as JSON objects. So every object that may be empty is built as an empty \stdClass by whoever builds
 * the value, and arrays stay lists.
 */
class JsonEncoder
{
    private const FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * The value as JSON
     *
     * @param mixed $value
     * @return string
     * @throws \JsonException When the value cannot be written as JSON, such as a resource or a too deep value
     */
    public function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
