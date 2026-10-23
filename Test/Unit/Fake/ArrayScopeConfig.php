<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Settings held in an array by path, at every scope; a test may change one between calls
 */
class ArrayScopeConfig implements ScopeConfigInterface
{
    /**
     * @param array<string, string|null> $values
     */
    public function __construct(
        public array $values = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool) ($this->values[$path] ?? false);
    }
}
