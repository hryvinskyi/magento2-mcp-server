<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * Tells JSON objects apart in JSON decoded into PHP arrays
 *
 * Decoded into arrays, `{}` and `[]` are both the empty array, so the empty array counts as an object; any other
 * list was a JSON array. Members named like integers come back with integer keys, so members() names every one by
 * a string again. An object whose members are named "0", "1", … in order decodes as the same array as a list and is
 * read as one: no place of the protocol where an object is expected (a message, its params, a tool's arguments)
 * names its members that way, and to PHP code the two are one value.
 */
class JsonObjects
{
    /**
     * Whether the decoded value was a JSON object
     *
     * @param mixed $value
     * @return bool
     * @phpstan-assert-if-true array<mixed> $value
     */
    public function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * The members of a decoded JSON object, each named by a string
     *
     * @param array<mixed> $object
     * @return array<string, mixed>
     */
    public function members(array $object): array
    {
        $members = [];
        foreach ($object as $name => $value) {
            $members[(string) $name] = $value;
        }

        return $members;
    }
}
