<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchema;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaBuilderInterface;

/**
 * A schema builder that counts its builds and hands each one to the builder it wraps, or answers a fixed schema,
 * marked as a fallback or not
 */
class CountingSchemaBuilder implements InputSchemaBuilderInterface
{
    /**
     * How many schemas were built
     *
     * @var int
     */
    public int $builds = 0;

    /**
     * @param InputSchemaBuilderInterface|null $inner
     * @param array<string, mixed> $schema The schema to answer without an inner builder
     * @param bool $fallback Whether that schema is a fallback
     */
    public function __construct(
        private readonly ?InputSchemaBuilderInterface $inner = null,
        private readonly array $schema = ['type' => 'object'],
        private readonly bool $fallback = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(AbilityInterface $ability, Route $route): InputSchema
    {
        $this->builds++;

        return $this->inner === null
            ? (new InputSchema($this->fallback))->withSchema($this->schema)
            : $this->inner->build($ability, $route);
    }
}
