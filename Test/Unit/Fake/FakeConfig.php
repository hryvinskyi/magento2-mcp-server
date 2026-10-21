<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\ConfigInterface;

/**
 * Settings a test chooses
 */
class FakeConfig implements ConfigInterface
{
    /**
     * @param bool $enabled
     * @param bool $readOnlyMode
     * @param bool $anonymousExposed
     */
    public function __construct(
        private readonly bool $enabled = true,
        private readonly bool $readOnlyMode = true,
        private readonly bool $anonymousExposed = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @inheritDoc
     */
    public function isReadOnlyMode(): bool
    {
        return $this->readOnlyMode;
    }

    /**
     * @inheritDoc
     */
    public function isAnonymousExposed(): bool
    {
        return $this->anonymousExposed;
    }
}
