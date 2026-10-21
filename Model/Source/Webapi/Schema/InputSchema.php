<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi\Schema;

/**
 * A built input schema, and whether it is only a stand-in for metadata the Web API could not give
 *
 * A fallback schema is right for this moment only: the method or one of its data types was not described, which can
 * pass (a service metadata build that failed, a type not registered yet), so it is never kept as if it were real.
 *
 * Created through InputSchemaFactory with the fallback flag; the schema goes in through withSchema(), which returns a
 * copy, so that the schema's keys never pass through the object manager's argument handling.
 */
class InputSchema
{
    /**
     * @var array<string, mixed>
     */
    private array $schema = ['type' => 'object'];

    /**
     * @param bool $fallback Whether the schema stands in for metadata the Web API could not give
     */
    public function __construct(
        private readonly bool $fallback = false
    ) {
    }

    /**
     * A copy holding this JSON Schema
     *
     * @param array<string,mixed> $schema
     * @return static
     */
    public function withSchema(array $schema): static
    {
        $copy = clone $this;
        $copy->schema = $schema;

        return $copy;
    }

    /**
     * The JSON Schema; a JSON object that may be empty is an empty \stdClass
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * Whether the schema stands in for metadata the Web API could not give, and so must not be kept
     *
     * @return bool
     */
    public function isFallback(): bool
    {
        return $this->fallback;
    }
}
