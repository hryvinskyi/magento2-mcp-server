<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi;

/**
 * Names a Web API service method as an ability, and its module as the ability's category
 *
 * From the service interface `Vendor\Module\Api\Sub\FooBarInterface` and the method `getList` the name is
 * `<module part>.<service part>.<method part>`:
 * - the module part is the module in snake case, prefixed with the vendor in snake case unless the vendor is Magento
 *   (`catalog`, `acme_shop_core`);
 * - the service part is the class path after the first `Api` namespace segment, or after the first `Service` segment
 *   when there is no `Api` one, without version segments (`V1`, `V2`, …) and without the `Interface` suffix, each
 *   segment in snake case, joined with `_` (`order_item_repository`, `module_service`). This follows the namespace
 *   rule Magento's own Web API type names use;
 * - the method part is the method in snake case.
 *
 * Snake case splits camel case and acronyms alike: `getCMSBlock` is `get_cms_block`.
 *
 * The namer works on strings only. A class it cannot name (no vendor and module, no `Api` or `Service` segment, or
 * nothing left of the service part) gives null; whether a name it gives is a valid ability name is the ability's own
 * check.
 */
class AbilityNamer
{
    private const API_SEGMENT = 'Api';
    private const SERVICE_SEGMENT = 'Service';
    private const DEFAULT_VENDOR = 'Magento';
    private const INTERFACE_SUFFIX = 'Interface';
    private const VERSION_SEGMENT = '/^V\d+$/D';

    /**
     * The ability name of a service method, or null for a class that cannot be named
     *
     * @param string $serviceClass
     * @param string $serviceMethod
     * @return string|null
     */
    public function nameOf(string $serviceClass, string $serviceMethod): ?string
    {
        $segments = $this->segmentsOf($serviceClass);
        $servicePart = $this->servicePartOf($segments);
        $methodPart = $this->snake($serviceMethod);
        if ($servicePart === null || $methodPart === '') {
            return null;
        }

        $vendor = $segments[0] ?? '';
        $module = $segments[1] ?? '';
        $modulePart = $vendor === self::DEFAULT_VENDOR
            ? $this->snake($module)
            : $this->snake($vendor) . '_' . $this->snake($module);

        return $modulePart . '.' . $servicePart . '.' . $methodPart;
    }

    /**
     * The Magento module a service class belongs to (`Magento_Catalog`), or null for a class outside a module
     *
     * @param string $serviceClass
     * @return string|null
     */
    public function categoryOf(string $serviceClass): ?string
    {
        $segments = $this->segmentsOf($serviceClass);
        if (count($segments) < 3) {
            return null;
        }

        return $segments[0] . '_' . $segments[1];
    }

    /**
     * An identifier in lowercase snake case, splitting camel case and acronyms (`getCMSBlock` → `get_cms_block`)
     *
     * @param string $identifier
     * @return string
     */
    public function snake(string $identifier): string
    {
        $split = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $identifier);

        return strtolower($split ?? $identifier);
    }

    /**
     * The namespace segments of a class, without empty ones
     *
     * @param string $class
     * @return list<string>
     */
    private function segmentsOf(string $class): array
    {
        return array_values(array_filter(
            explode('\\', $class),
            static fn (string $segment): bool => $segment !== ''
        ));
    }

    /**
     * The service part of the name, or null when the class has no `Api` or `Service` segment after its module
     *
     * @param list<string> $segments
     * @return string|null
     */
    private function servicePartOf(array $segments): ?string
    {
        $marker = $this->markerPosition($segments);
        if ($marker === null) {
            return null;
        }

        $parts = [];
        foreach (array_slice($segments, $marker + 1) as $segment) {
            if (preg_match(self::VERSION_SEGMENT, $segment) === 1) {
                continue;
            }
            $parts[] = $segment;
        }

        $last = array_key_last($parts);
        if ($last !== null && str_ends_with($parts[$last], self::INTERFACE_SUFFIX)) {
            $parts[$last] = substr($parts[$last], 0, -strlen(self::INTERFACE_SUFFIX));
        }

        $snakeParts = array_values(array_filter(
            array_map(fn (string $part): string => $this->snake($part), $parts),
            static fn (string $part): bool => $part !== ''
        ));

        return $snakeParts === [] ? null : implode('_', $snakeParts);
    }

    /**
     * Index of the first `Api` segment after vendor and module, else of the first `Service` segment, else null
     *
     * @param list<string> $segments
     * @return int|null
     */
    private function markerPosition(array $segments): ?int
    {
        $afterModule = array_slice($segments, 2, null, true);

        $api = array_search(self::API_SEGMENT, $afterModule, true);
        if (is_int($api)) {
            return $api;
        }

        $service = array_search(self::SERVICE_SEGMENT, $afterModule, true);

        return is_int($service) ? $service : null;
    }
}
