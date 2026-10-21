<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;

/**
 * An ability source over a fixed list, which records how it was used
 *
 * Invoking an ability runs the given behaviour, or returns the arguments it was given.
 */
class FakeAbilitySource implements AbilitySourceInterface
{
    /**
     * How many times the abilities were listed
     *
     * @var int
     */
    public int $listings = 0;

    /**
     * Every call, as the ability name and the arguments
     *
     * @var list<array{string, array<string, mixed>}>
     */
    public array $invocations = [];

    /**
     * @param string $code
     * @param list<AbilityInterface> $abilities
     * @param bool $allowed
     * @param (\Closure(AbilityInterface, array<string, mixed>): mixed)|null $behaviour
     */
    public function __construct(
        private readonly string $code,
        private readonly array $abilities,
        private readonly bool $allowed = true,
        private readonly ?\Closure $behaviour = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function abilities(): iterable
    {
        $this->listings++;

        yield from $this->abilities;
    }

    /**
     * @inheritDoc
     */
    public function inputSchema(AbilityInterface $ability): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'description' => $this->code];
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(AbilityInterface $ability): bool
    {
        return $this->allowed;
    }

    /**
     * @inheritDoc
     */
    public function invoke(AbilityInterface $ability, array $arguments): mixed
    {
        $this->invocations[] = [$ability->getName(), $arguments];

        return $this->behaviour === null ? $arguments : ($this->behaviour)($ability, $arguments);
    }
}
