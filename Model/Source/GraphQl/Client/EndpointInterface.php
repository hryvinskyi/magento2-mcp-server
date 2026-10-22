<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

/**
 * Where GraphQL requests go, and the store view they run in
 *
 * Both identify the schema the endpoint answers with, so a cached schema is kept under both.
 */
interface EndpointInterface
{
    /**
     * The absolute URL of the GraphQL endpoint
     *
     * @return string
     * @throws GraphQlTransportException When no valid URL can be named
     */
    public function url(): string;

    /**
     * Code of the store view the requests run in, sent as the `Store` header
     *
     * @return string
     * @throws GraphQlTransportException When the installation has no store view to run in
     */
    public function storeCode(): string;
}
