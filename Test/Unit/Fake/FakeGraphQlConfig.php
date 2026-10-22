<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfigInterface;

/**
 * GraphQL settings a test sets
 */
class FakeGraphQlConfig implements GraphQlConfigInterface
{
    /**
     * @param bool $enabled
     * @param bool $mutationExposed
     * @param string $endpointUrl
     * @param string $storeCode
     */
    public function __construct(
        public bool $enabled = true,
        public bool $mutationExposed = false,
        public string $endpointUrl = '',
        public string $storeCode = ''
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
    public function isMutationExposed(): bool
    {
        return $this->mutationExposed;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    /**
     * @inheritDoc
     */
    public function getStoreCode(): string
    {
        return $this->storeCode;
    }
}
