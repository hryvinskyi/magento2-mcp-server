<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi;

use Magento\Webapi\Model\Config\Converter;
use Magento\Webapi\Model\ConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * The `webapi.xml` routes that become abilities: one route per service method
 *
 * Routes are read from the merged Web API configuration. Skipped are:
 * - a route that needs the ACL resource `self`, which stands for the customer the call is made for: admin and
 *   integration callers have no such customer;
 * - a route whose service is in the di.xml list `excludedServices` (the module's own endpoint, for one).
 *
 * When several routes call the same service method, one is kept: the one with the fewest forced parameters, then the
 * lowest version, then the shortest URL (and, for a stable answer, the lower URL and HTTP method in string order).
 * Routes with the resource `anonymous` are kept.
 *
 * The routes come back sorted by URL and then HTTP method. A route's ACL resources are a list of resource ids: the
 * configuration keeps them as a map of id to `true`, and only its keys are resources. A route keeps its `secure`
 * flag, which asks for HTTPS.
 */
class RouteIndex
{
    private const SELF_RESOURCE = 'self';

    /**
     * @param ConfigInterface $config
     * @param RouteFactory $routeFactory
     * @param LoggerInterface $logger
     * @param string[] $excludedServices Service interfaces whose routes never become abilities
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RouteFactory $routeFactory,
        private readonly LoggerInterface $logger,
        private readonly array $excludedServices = []
    ) {
    }

    /**
     * One route per service method, sorted by URL and then HTTP method
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        $chosen = [];
        foreach ($this->candidates() as $route) {
            $key = $route->getServiceClass() . '::' . $route->getServiceMethod();
            $current = $chosen[$key] ?? null;
            if ($current === null || $this->isPreferred($route, $current)) {
                $chosen[$key] = $route;
            }
        }

        $routes = array_values($chosen);
        usort(
            $routes,
            static fn (Route $a, Route $b): int => [$a->getUrl(), $a->getHttpMethod()]
                <=> [$b->getUrl(), $b->getHttpMethod()]
        );

        return $routes;
    }

    /**
     * Every route of the configuration that may become an ability
     *
     * @return \Generator<Route>
     */
    private function candidates(): \Generator
    {
        $excluded = array_map(static fn (string $class): string => ltrim($class, '\\'), $this->excludedServices);
        $routes = $this->config->getServices()[Converter::KEY_ROUTES] ?? [];
        if (!is_array($routes)) {
            return;
        }

        foreach ($routes as $url => $byMethod) {
            if (!is_array($byMethod)) {
                continue;
            }
            foreach ($byMethod as $httpMethod => $data) {
                $route = is_array($data) ? $this->routeOf((string) $url, (string) $httpMethod, $data) : null;
                if ($route === null
                    || in_array(self::SELF_RESOURCE, $route->getAclResources(), true)
                    || in_array(ltrim($route->getServiceClass(), '\\'), $excluded, true)
                ) {
                    continue;
                }

                yield $route;
            }
        }
    }

    /**
     * The route value of one configured route, or null (logged) for one the configuration describes incompletely
     *
     * @param string $url
     * @param string $httpMethod
     * @param array<mixed> $data
     * @return Route|null
     */
    private function routeOf(string $url, string $httpMethod, array $data): ?Route
    {
        $service = $data[Converter::KEY_SERVICE] ?? null;
        $class = is_array($service) ? ($service[Converter::KEY_SERVICE_CLASS] ?? null) : null;
        $method = is_array($service) ? ($service[Converter::KEY_SERVICE_METHOD] ?? null) : null;
        $resources = $data[Converter::KEY_ACL_RESOURCES] ?? [];
        $limit = $data[Converter::KEY_INPUT_ARRAY_SIZE_LIMIT] ?? null;

        if (!is_string($class) || !is_string($method)) {
            $this->logSkip($url, $httpMethod, 'the route names no service class and method.');

            return null;
        }

        try {
            return $this->routeFactory->create([
                'url' => $url,
                'httpMethod' => $httpMethod,
                'serviceClass' => $class,
                'serviceMethod' => $method,
                'aclResources' => is_array($resources) ? array_map('strval', array_keys($resources)) : [],
                'inputArraySizeLimit' => is_numeric($limit) ? (int) $limit : null,
                'secure' => (bool) ($data[Converter::KEY_SECURE] ?? false),
            ])->withParameters($this->parametersOf($data[Converter::KEY_DATA_PARAMETERS] ?? []));
        } catch (\InvalidArgumentException $exception) {
            $this->logSkip($url, $httpMethod, $exception->getMessage());

            return null;
        }
    }

    /**
     * Log why a configured route gives no route value
     *
     * @param string $url
     * @param string $httpMethod
     * @param string $reason
     * @return void
     */
    private function logSkip(string $url, string $httpMethod, string $reason): void
    {
        $this->logger->warning(sprintf('MCP skips the Web API route %s %s: %s', $httpMethod, $url, $reason));
    }

    /**
     * The route's parameters, keyed by name, each with its force flag and value
     *
     * @param mixed $parameters
     * @return array<string, array{force: bool, value: mixed}>
     */
    private function parametersOf(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            return [];
        }

        $result = [];
        foreach ($parameters as $name => $parameter) {
            if (!is_array($parameter)) {
                continue;
            }
            $result[(string) $name] = [
                'force' => (bool) ($parameter[Converter::KEY_FORCE] ?? false),
                'value' => $parameter[Converter::KEY_VALUE] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Whether a route is a better way to reach its service method than the one chosen so far
     *
     * @param Route $route
     * @param Route $current
     * @return bool
     */
    private function isPreferred(Route $route, Route $current): bool
    {
        return $this->rank($route) < $this->rank($current);
    }

    /**
     * Sort key of a route among routes to the same service method: lower is preferred
     *
     * @param Route $route
     * @return array{int, int, int, string, string}
     */
    private function rank(Route $route): array
    {
        return [
            count($route->getForcedParameterNames()),
            $route->getVersionNumber(),
            strlen($route->getUrl()),
            $route->getUrl(),
            $route->getHttpMethod(),
        ];
    }
}
