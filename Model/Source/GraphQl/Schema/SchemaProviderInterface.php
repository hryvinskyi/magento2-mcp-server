<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

/**
 * The GraphQL schema of the store's endpoint
 */
interface SchemaProviderInterface
{
    /**
     * The schema, or null when it cannot be read now
     *
     * It cannot be read when the endpoint fails, refuses introspection, or answers with errors. The reason is logged;
     * the caller offers no GraphQL abilities meanwhile.
     *
     * @return IntrospectedSchema|null
     */
    public function schema(): ?IntrospectedSchema;
}
