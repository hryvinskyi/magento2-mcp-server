<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Call;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Checks that a client's selection set selects inside the one root field it was sent for, and nothing else
 *
 * The selection is written into the operation right after the root field, so anything after its closing brace would
 * sit at the operation's root: `{ items { sku } } customer { email }` would select a second root field, and
 * `{ cart { id } } placeOrder(…) { … }` would run a second mutation, both under the exposure decision of the ability
 * that was called. So a selection must be exactly one group in braces:
 * - it starts with `{`, after optional whitespace;
 * - only whitespace may follow the brace that closes that first one;
 * - braces are counted by a small tokenizer that skips string literals, `"…"` with backslash escapes and `"""…"""`
 *   block strings, so a brace inside a string argument counts for nothing;
 * - a `#` outside a string, the start of a GraphQL comment, is refused: a comment would hide the rest of its line from
 *   the server but not from this check;
 * - a string that is never closed, or a regular string spanning a line break (which GraphQL forbids), is refused.
 *
 * Everything else inside the group (fields, aliases, arguments, fragments, directives) is left to the server's own
 * parser and validation.
 */
class SelectionValidator
{
    private const WHITESPACE = " \t\n\r";
    private const BLOCK_QUOTE = '"""';
    private const ESCAPED_BLOCK_QUOTE = '\\"""';

    /**
     * Refuse a selection that is not exactly one group in braces
     *
     * @param string $selection
     * @return void
     * @throws LocalizedException
     */
    public function validate(string $selection): void
    {
        $text = ltrim($selection, self::WHITESPACE);
        if (!str_starts_with($text, '{')) {
            $this->refuse(__('it must start with "%1"', '{'));
        }

        $depth = 0;
        $length = strlen($text);
        $position = 0;
        while ($position < $length) {
            $char = $text[$position];
            if ($char === '"') {
                $position = $this->afterString($text, $position);
                continue;
            }
            if ($char === '#') {
                $this->refuse(__('"%1" comments are not allowed', '#'));
            }
            if ($char === '{') {
                $depth++;
            }
            if ($char === '}') {
                $depth--;
            }
            if ($depth === 0) {
                $this->assertNothingAfter($text, $position);

                return;
            }
            $position++;
        }

        $this->refuse(__('its braces are not balanced'));
    }

    /**
     * Refuse anything but whitespace after the brace that closes the group
     *
     * @param string $text
     * @param int $closingBrace
     * @return void
     * @throws LocalizedException
     */
    private function assertNothingAfter(string $text, int $closingBrace): void
    {
        if (trim(substr($text, $closingBrace + 1), self::WHITESPACE) !== '') {
            $this->refuse(__('nothing may follow the brace that closes it'));
        }
    }

    /**
     * The position right after the string literal that starts here
     *
     * @param string $text
     * @param int $start Position of the opening quote
     * @return int
     * @throws LocalizedException When the string is never closed
     */
    private function afterString(string $text, int $start): int
    {
        if (substr($text, $start, 3) === self::BLOCK_QUOTE) {
            return $this->afterBlockString($text, $start + 3);
        }

        $length = strlen($text);
        for ($position = $start + 1; $position < $length; $position++) {
            $char = $text[$position];
            if ($char === '\\') {
                $position++;
                continue;
            }
            if ($char === "\n" || $char === "\r") {
                break;
            }
            if ($char === '"') {
                return $position + 1;
            }
        }

        $this->refuse(__('a string in it is not closed on its line'));
    }

    /**
     * The position right after the block string whose content starts here
     *
     * @param string $text
     * @param int $contentStart
     * @return int
     * @throws LocalizedException When the block string is never closed
     */
    private function afterBlockString(string $text, int $contentStart): int
    {
        $length = strlen($text);
        for ($position = $contentStart; $position < $length; $position++) {
            if (substr($text, $position, 4) === self::ESCAPED_BLOCK_QUOTE) {
                $position += 3;
                continue;
            }
            if (substr($text, $position, 3) === self::BLOCK_QUOTE) {
                return $position + 3;
            }
        }

        $this->refuse(__('a block string in it is not closed'));
    }

    /**
     * Refuse the selection, saying why
     *
     * @param Phrase $reason
     * @return never
     * @throws LocalizedException
     */
    private function refuse(Phrase $reason): never
    {
        throw new LocalizedException(__(
            'The selection must be exactly one GraphQL selection set in braces, such as %1: %2.',
            '{ items { sku name } }',
            $reason->render()
        ));
    }
}
