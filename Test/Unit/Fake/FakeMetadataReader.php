<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\Webapi\Metadata\MetadataReaderInterface;

/**
 * Web API metadata over fixed methods and types, which counts how often each was read
 */
class FakeMetadataReader implements MetadataReaderInterface
{
    /**
     * How many times a method was read
     *
     * @var int
     */
    public int $methodReads = 0;

    /**
     * @param array<string, array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, required: bool, documentation: string, default?: mixed}>
     * }> $methods Method metadata by `<class>::<method>`
     * @param array<string, array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, documentation: string}>
     * }> $types Type metadata by Web API type name
     */
    public function __construct(
        private readonly array $methods = [],
        private readonly array $types = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function methodOf(string $serviceClass, string $serviceMethod, string $version): ?array
    {
        $this->methodReads++;

        return $this->methods[$serviceClass . '::' . $serviceMethod] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function typeOf(string $typeName): ?array
    {
        return $this->types[$typeName] ?? null;
    }
}
