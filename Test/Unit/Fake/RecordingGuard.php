<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\InvocationGuardInterface;

/**
 * An invocation guard that gives the same answer for every call and remembers what it was asked
 */
class RecordingGuard implements InvocationGuardInterface
{
    /**
     * Ability name and arguments of every call the guard was asked about
     *
     * @var list<array{string, array<string, mixed>}>
     */
    public array $asked = [];

    /**
     * @param string|null $refusal
     */
    public function __construct(
        private readonly ?string $refusal = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refusal(AbilityInterface $ability, array $arguments): ?string
    {
        $this->asked[] = [$ability->getName(), $arguments];

        return $this->refusal;
    }
}
