<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi\Metadata;

/**
 * What Magento's Web API knows about a service method and the types it takes, in typed shapes
 *
 * Type names are Web API type names, not class names: a scalar (`string`, `int`, `float`, `double`, `boolean`),
 * `anyType` for `mixed`, a data interface's translated name (`CatalogDataProductInterface`,
 * `FrameworkSearchCriteriaInterface`), or any of these followed by `[]` for a list of them. Field names of a data
 * type are in snake case, the way REST takes them.
 */
interface MetadataReaderInterface
{
    /**
     * The documentation and parameters of a service method, or null when the Web API does not describe it
     *
     * A parameter is required when the method's signature does not make it optional.
     *
     * @param string $serviceClass
     * @param string $serviceMethod
     * @param string $version Route version (`V1`)
     * @return array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, required: bool, documentation: string, default?: mixed}>
     * }|null
     */
    public function methodOf(string $serviceClass, string $serviceMethod, string $version): ?array;

    /**
     * The documentation and fields of a data type, or null when the Web API has not registered the type
     *
     * A field carries no required flag: it says what a stored record holds, never what a call must send.
     *
     * @param string $typeName Web API type name, without `[]`
     * @return array{
     *     documentation: string,
     *     parameters: array<string, array{type: string, documentation: string}>
     * }|null
     */
    public function typeOf(string $typeName): ?array;
}
