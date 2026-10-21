<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Webapi\Model\ConfigInterface;

/**
 * Web API configuration holding the routes it is given, in the shape Magento's converter builds
 */
class FakeWebapiConfig implements ConfigInterface
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $routes Route data by URL and HTTP method
     */
    public function __construct(
        private readonly array $routes
    ) {
    }

    /**
     * The configured routes, with no services section
     *
     * @return array<string, mixed>
     */
    public function getServices()
    {
        return ['routes' => $this->routes, 'services' => []];
    }

    /**
     * One route's data, as the converter writes it
     *
     * @param string $class
     * @param string $method
     * @param list<string> $resources
     * @param array<string, bool> $forced Forced flag by parameter name
     * @param int|null $inputArraySizeLimit
     * @param bool $secure
     * @return array<string, mixed>
     */
    public static function route(
        string $class,
        string $method,
        array $resources = ['Magento_Catalog::products'],
        array $forced = [],
        ?int $inputArraySizeLimit = null,
        bool $secure = false
    ): array {
        $parameters = [];
        foreach ($forced as $name => $force) {
            $parameters[$name] = ['force' => $force, 'value' => '%customer_id%'];
        }

        return [
            'secure' => $secure,
            'service' => ['class' => $class, 'method' => $method],
            'resources' => array_fill_keys($resources, true),
            'parameters' => $parameters,
            'input-array-size-limit' => $inputArraySizeLimit,
        ];
    }
}
