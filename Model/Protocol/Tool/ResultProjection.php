<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;

/**
 * Keeps only the fields of an ability's result that the model names in the `fields` of `execute_ability`
 *
 * A field is a dot-separated path from the top of the result, such as `total_count` or `items.sku`. A path through a
 * list applies to every element of the list, so `items.sku` keeps the SKU of each item. A named field keeps everything
 * under it, and a value that is neither an object nor a list is kept as it is. A path that names no field of the result
 * is not an error, because the ability has already run: it becomes a note that names the fields found where the path
 * left the result.
 */
class ResultProjection
{
    /**
     * The fields as paths of field names
     *
     * @param list<string> $fields
     * @return list<non-empty-list<string>>
     * @throws InvalidToolArgumentException When a field is not a dot-separated path of names
     */
    public function paths(array $fields): array
    {
        $paths = [];
        foreach ($fields as $field) {
            $names = explode('.', trim($field));
            if (in_array('', $names, true)) {
                throw new InvalidToolArgumentException(__(
                    'The field "%1" is not a dot-separated path of field names, such as "items.sku".',
                    $field
                )->render());
            }
            $paths[] = $names;
        }

        return $paths;
    }

    /**
     * The value with only the fields on these paths
     *
     * @param mixed $value
     * @param list<non-empty-list<string>> $paths
     * @return mixed
     */
    public function project(mixed $value, array $paths): mixed
    {
        $value = $this->plain($value);
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $element): mixed => $this->project($element, $paths), $value);
        }

        $whole = [];
        $deeper = [];
        foreach ($paths as $path) {
            $rest = array_slice($path, 1);
            if ($rest === []) {
                $whole[$path[0]] = true;
                continue;
            }
            $deeper[$path[0]][] = $rest;
        }

        $kept = [];
        foreach ($value as $key => $item) {
            $name = (string) $key;
            if (isset($whole[$name])) {
                $kept[$name] = $item;
                continue;
            }
            if (isset($deeper[$name])) {
                $kept[$name] = $this->project($item, $deeper[$name]);
            }
        }

        return $kept;
    }

    /**
     * A note for each path that names no field of the value
     *
     * @param mixed $value
     * @param list<non-empty-list<string>> $paths
     * @return list<string>
     */
    public function misses(mixed $value, array $paths): array
    {
        $notes = [];
        foreach ($paths as $path) {
            $note = $this->miss([$value], $path, 0);
            if ($note !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * The note for a path whose name at this depth is a field of none of the objects it has reached
     *
     * Null when the path holds, or when there is no object to look into.
     *
     * @param list<mixed> $values What the path has reached above this depth
     * @param non-empty-list<string> $path
     * @param int $depth
     * @return string|null
     */
    private function miss(array $values, array $path, int $depth): ?string
    {
        $objects = $this->objects($values);
        if ($objects === []) {
            return null;
        }

        $name = $path[$depth] ?? '';
        $found = [];
        $fieldNames = [];
        foreach ($objects as $object) {
            foreach (array_keys($object) as $key) {
                $fieldNames[(string) $key] = true;
            }
            if (array_key_exists($name, $object)) {
                $found[] = $object[$name];
            }
        }

        if ($found === []) {
            return $this->missNote($path, $depth, array_keys($fieldNames));
        }

        return $depth + 1 < count($path) ? $this->miss($found, $path, $depth + 1) : null;
    }

    /**
     * The note that a path names no field, with the fields there are where it left the result
     *
     * @param non-empty-list<string> $path
     * @param int $depth
     * @param list<string> $fieldNames
     * @return string
     */
    private function missNote(array $path, int $depth, array $fieldNames): string
    {
        $field = implode('.', $path);
        if ($depth === 0) {
            return __(
                'The result has no field "%1". Its top-level fields are: %2.',
                $field,
                implode(', ', $fieldNames)
            )->render();
        }

        return __(
            'The result has no field "%1". The fields under "%2" are: %3.',
            $field,
            implode('.', array_slice($path, 0, $depth)),
            implode(', ', $fieldNames)
        )->render();
    }

    /**
     * The objects among these values, the objects inside lists included
     *
     * @param array<mixed> $values
     * @return list<array<mixed>>
     */
    private function objects(array $values): array
    {
        $objects = [];
        foreach ($values as $value) {
            $value = $this->plain($value);
            if (!is_array($value) || $value === []) {
                continue;
            }
            if (array_is_list($value)) {
                array_push($objects, ...$this->objects($value));
                continue;
            }
            $objects[] = $value;
        }

        return $objects;
    }

    /**
     * The value as the JSON encoder would see it: an object as its public fields
     *
     * @param mixed $value
     * @return mixed
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $this->plain($value->jsonSerialize());
        }

        return is_object($value) ? get_object_vars($value) : $value;
    }
}
