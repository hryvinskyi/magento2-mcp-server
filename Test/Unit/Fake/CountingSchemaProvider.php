<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\SchemaProviderInterface;

/**
 * A schema provider that counts its reads and answers the schema the test sets, or null for a failed read
 */
class CountingSchemaProvider implements SchemaProviderInterface
{
    /**
     * @var int
     */
    public int $reads = 0;

    /**
     * @param IntrospectedSchema|null $schema
     */
    public function __construct(
        public ?IntrospectedSchema $schema
    ) {
    }

    /**
     * @inheritDoc
     */
    public function schema(): ?IntrospectedSchema
    {
        $this->reads++;

        return $this->schema;
    }
}
