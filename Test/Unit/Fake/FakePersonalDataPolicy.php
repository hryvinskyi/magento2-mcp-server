<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;

/**
 * A personal data policy that hides or not as told, showing personal data to the abilities named
 */
class FakePersonalDataPolicy implements PersonalDataPolicyInterface
{
    /**
     * @param bool $hiding
     * @param list<string> $shownTo
     */
    public function __construct(
        private readonly bool $hiding = true,
        private readonly array $shownTo = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isHidingOn(): bool
    {
        return $this->hiding;
    }

    /**
     * @inheritDoc
     */
    public function hidesFor(string $ability): bool
    {
        return $this->hiding && !in_array($ability, $this->shownTo, true);
    }
}
