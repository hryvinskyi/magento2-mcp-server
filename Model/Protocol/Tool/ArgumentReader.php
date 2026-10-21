<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Model\Protocol\Exception\InvalidToolArgumentException;
use Hryvinskyi\McpServer\Model\Protocol\JsonObjects;

/**
 * Reads typed arguments of a tool call, refusing a wrong type with a message the model can act on
 *
 * An argument that is absent or null takes its default. Types are strict: a number sent as a string is refused rather
 * than guessed at.
 */
class ArgumentReader
{
    /**
     * @param JsonObjects $jsonObjects
     */
    public function __construct(
        private readonly JsonObjects $jsonObjects
    ) {
    }

    /**
     * A string argument, or null when it is absent
     *
     * @param array<string,mixed> $arguments
     * @param string $name
     * @return string|null
     * @throws InvalidToolArgumentException When the argument is not a string
     */
    public function optionalString(array $arguments, string $name): ?string
    {
        $value = $arguments[$name] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new InvalidToolArgumentException(__('The argument "%1" must be a string.', $name)->render());
    }

    /**
     * A non-empty string argument
     *
     * @param array<string,mixed> $arguments
     * @param string $name
     * @return string
     * @throws InvalidToolArgumentException When the argument is absent, empty or not a string
     */
    public function requiredString(array $arguments, string $name): string
    {
        $value = $this->optionalString($arguments, $name);
        if ($value === null || trim($value) === '') {
            throw new InvalidToolArgumentException(__('The argument "%1" is required.', $name)->render());
        }

        return $value;
    }

    /**
     * An integer argument within bounds
     *
     * @param array<string,mixed> $arguments
     * @param string $name
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     * @throws InvalidToolArgumentException When the argument is not an integer or out of bounds
     */
    public function integer(array $arguments, string $name, int $default, int $min, int $max): int
    {
        $value = $arguments[$name] ?? $default;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidToolArgumentException(
                __('The argument "%1" must be an integer from %2 to %3.', $name, $min, $max)->render()
            );
        }

        return $value;
    }

    /**
     * An object argument, as its members; no members when it is absent
     *
     * @param array<string,mixed> $arguments
     * @param string $name
     * @return array<string, mixed>
     * @throws InvalidToolArgumentException When the argument is not a JSON object
     */
    public function object(array $arguments, string $name): array
    {
        $value = $arguments[$name] ?? [];
        if (!$this->jsonObjects->isObject($value)) {
            throw new InvalidToolArgumentException(
                __('The argument "%1" must be a JSON object keyed by name.', $name)->render()
            );
        }

        return $this->jsonObjects->members($value);
    }
}
