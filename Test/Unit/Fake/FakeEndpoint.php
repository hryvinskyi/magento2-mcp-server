<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;

/**
 * An endpoint the test sets and changes, or one that cannot be named
 */
class FakeEndpoint implements EndpointInterface
{
    /**
     * @param string $url
     * @param string $storeCode
     * @param bool $unnamed Whether naming the endpoint fails
     */
    public function __construct(
        public string $url = 'https://shop.test/graphql',
        public string $storeCode = 'default',
        public bool $unnamed = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function url(): string
    {
        if ($this->unnamed) {
            throw new GraphQlTransportException('The GraphQL endpoint URL setting is not valid.');
        }

        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function storeCode(): string
    {
        return $this->storeCode;
    }
}
