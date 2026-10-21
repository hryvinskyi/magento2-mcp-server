<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi;

/**
 * One `webapi.xml` route, as an immutable value: where it is, which service method it calls, the ACL resources it
 * needs and the parameters it fills in itself
 *
 * Created through RouteFactory with the scalar values and the resource list; the parameters go in through
 * withParameters(), which returns a copy, so that the route's configuration never passes through the object
 * manager's argument handling.
 */
class Route
{
    /**
     * @var array<string, array{force: bool, value: mixed}>
     */
    private array $parameters = [];

    /**
     * @param string $url Route URL as declared, with its version segment (`/V1/products/:sku`)
     * @param string $httpMethod `GET`, `POST`, `PUT` or `DELETE`
     * @param string $serviceClass Service interface the route calls
     * @param string $serviceMethod Method of the service interface the route calls
     * @param list<string> $aclResources Every ACL resource a caller needs
     * @param int|null $inputArraySizeLimit Limit of array sizes in the input, null for Magento's default
     * @param bool $secure Whether the route may only be called over HTTPS
     * @throws \InvalidArgumentException When the URL has no version segment or a value is blank
     */
    public function __construct(
        private readonly string $url,
        private readonly string $httpMethod,
        private readonly string $serviceClass,
        private readonly string $serviceMethod,
        private readonly array $aclResources = [],
        private readonly ?int $inputArraySizeLimit = null,
        private readonly bool $secure = false
    ) {
        if (preg_match('#^/V\d+/#', $url) !== 1) {
            throw new \InvalidArgumentException(sprintf('The route URL "%s" does not start with a version.', $url));
        }
        if (trim($httpMethod) === '' || trim($serviceClass) === '' || trim($serviceMethod) === '') {
            throw new \InvalidArgumentException(
                sprintf('The route "%s" needs an HTTP method, a service class and a service method.', $url)
            );
        }
    }

    /**
     * A copy of the route with these parameters, keyed by parameter name (a dotted path for a nested field)
     *
     * @param array<string,array{force:bool,value:mixed}> $parameters
     * @return static
     */
    public function withParameters(array $parameters): static
    {
        $copy = clone $this;
        $copy->parameters = $parameters;

        return $copy;
    }

    /**
     * Route URL as declared (`/V1/products/:sku`)
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * HTTP method of the route
     *
     * @return string
     */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /**
     * Service interface the route calls
     *
     * @return string
     */
    public function getServiceClass(): string
    {
        return $this->serviceClass;
    }

    /**
     * Method of the service interface the route calls
     *
     * @return string
     */
    public function getServiceMethod(): string
    {
        return $this->serviceMethod;
    }

    /**
     * Every ACL resource a caller needs
     *
     * @return list<string>
     */
    public function getAclResources(): array
    {
        return $this->aclResources;
    }

    /**
     * Limit of array sizes in the input, null for Magento's default
     *
     * @return int|null
     */
    public function getInputArraySizeLimit(): ?int
    {
        return $this->inputArraySizeLimit;
    }

    /**
     * Whether the route may only be called over HTTPS
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * Names of the parameters in the URL's path, in their order (`/V1/orders/:parent_id` has `parent_id`)
     *
     * @return list<string>
     */
    public function getPathParameterNames(): array
    {
        preg_match_all('#/:([^/]+)#', $this->url, $matches);

        return $matches[1];
    }

    /**
     * Path parameters of an update route that name the record to update rather than a parameter of the method
     *
     * Magento's REST API copies such a value into the record the method saves when the route's HTTP method is PUT
     * (`PUT /V1/orders/:parent_id` on `save($entity)` updates the record `parent_id` names). Names are compared
     * the way the Web API reads them, so `parent_id` and `parentId` are the same parameter.
     *
     * @param list<string> $methodParameterNames Names of the parameters the service method takes
     * @return list<string>
     */
    public function pathIdNames(array $methodParameterNames): array
    {
        if (strtoupper($this->httpMethod) !== 'PUT') {
            return [];
        }

        $taken = array_map(fn (string $name): string => $this->comparable($name), $methodParameterNames);

        return array_values(array_filter(
            $this->getPathParameterNames(),
            fn (string $name): bool => !in_array($this->comparable($name), $taken, true)
        ));
    }

    /**
     * Parameters the route fills in, keyed by name: forced ones replace what the caller sent, the others are defaults
     *
     * @return array<string, array{force: bool, value: mixed}>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Names of the parameters the route forces, whatever the caller sends
     *
     * @return list<string>
     */
    public function getForcedParameterNames(): array
    {
        $names = [];
        foreach ($this->parameters as $name => $parameter) {
            if ($parameter['force']) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Version segment of the URL (`V1`)
     *
     * @return string
     */
    public function getVersion(): string
    {
        return explode('/', $this->url)[1] ?? '';
    }

    /**
     * Version as a number (`V2` is 2), for ordering routes
     *
     * @return int
     */
    public function getVersionNumber(): int
    {
        return (int) substr($this->getVersion(), 1);
    }

    /**
     * The route in the source's own terms, `<HTTP method> <URL>` (`GET /V1/products`)
     *
     * @return string
     */
    public function getReference(): string
    {
        return $this->httpMethod . ' ' . $this->url;
    }

    /**
     * A parameter name in the form that makes its snake-case and camel-case spellings equal
     *
     * @param string $name
     * @return string
     */
    private function comparable(string $name): string
    {
        return strtolower(str_replace('_', '', $name));
    }
}
