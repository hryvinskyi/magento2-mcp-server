<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi\Metadata;

use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Magento\Framework\Api\SimpleDataObjectConverter;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Reflection\TypeProcessor;
use Magento\Webapi\Model\Config\ClassReflector;
use Magento\Webapi\Model\ServiceMetadata;
use Psr\Log\LoggerInterface;

/**
 * Reads method and type metadata from Magento's Web API service metadata, the data its Swagger schema is built from
 *
 * - A method is found in the service's metadata (service name from the class and the route version) by its real
 *   method name: the metadata keys methods by their SOAP operation alias, which can differ from the method name.
 * - Data types are read from the type processor, which knows a type only once it is registered. Loading the service
 *   metadata registers every type the Web API uses, so it is loaded before any type is read, and a type is always
 *   asked for by its Web API type name, never by its class name.
 * - The type processor names fields in camel case; they are turned into snake case, as Magento's Swagger schema does,
 *   since that is how REST documents them.
 *
 * Magento builds the service metadata for all services at once, and one service whose docblocks it cannot read (an
 * `array` parameter without an item type, for one) makes the whole build fail. Then the failure is logged and each
 * method is read by reflecting its own service class, which also registers the types it uses: a faulty service loses
 * its own metadata, never the other services'.
 *
 * A failed build is slow and fails the same way until the code changes, so it is remembered for five minutes in the
 * module's cache type, under the tag of the schema entries: requests in that time read by reflection at once, and a
 * deployment that cleans the cache type lets the next request try again. While the cache type is disabled nothing is
 * remembered, and every request tries the build once.
 */
class ServiceMetadataReader implements MetadataReaderInterface, ResetAfterRequestInterface
{
    private const ANY_TYPE = 'anyType';

    /**
     * Cache key of the memory of a failed build
     */
    private const FAILED_BUILD_KEY = 'hryvinskyi_mcp_service_metadata_failed';

    /**
     * How long a failed build is remembered, in seconds
     */
    public const FAILED_BUILD_LIFETIME = 300;

    /**
     * Whether Magento's service metadata could be built during this request; null until it is first needed
     *
     * @var bool|null
     */
    private ?bool $servicesLoaded = null;

    /**
     * @param ServiceMetadata $serviceMetadata
     * @param TypeProcessor $typeProcessor
     * @param ClassReflector $classReflector
     * @param LoggerInterface $logger
     * @param FrontendInterface $cache The module's cache type
     */
    public function __construct(
        private readonly ServiceMetadata $serviceMetadata,
        private readonly TypeProcessor $typeProcessor,
        private readonly ClassReflector $classReflector,
        private readonly LoggerInterface $logger,
        private readonly FrontendInterface $cache
    ) {
    }

    /**
     * @inheritDoc
     */
    public function methodOf(string $serviceClass, string $serviceMethod, string $version): ?array
    {
        $method = $this->servicesLoaded()
            ? $this->fromServiceMetadata($serviceClass, $serviceMethod, $version)
            : $this->fromReflection($serviceClass, $serviceMethod);
        if ($method === null) {
            return null;
        }

        $interface = $method['interface'] ?? [];
        $input = is_array($interface) ? ($interface['in'] ?? []) : [];

        return [
            'documentation' => $this->text($method['documentation'] ?? ''),
            'parameters' => $this->parametersOf(is_array($input) ? ($input['parameters'] ?? []) : []),
        ];
    }

    /**
     * @inheritDoc
     */
    public function typeOf(string $typeName): ?array
    {
        $this->servicesLoaded();
        try {
            $type = $this->typeProcessor->getTypeData($typeName);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $fields = [];
        foreach ($this->parametersOf($type['parameters'] ?? []) as $name => $field) {
            $fields[SimpleDataObjectConverter::camelCaseToSnakeCase($name)] = [
                'type' => $field['type'],
                'documentation' => $field['documentation'],
            ];
        }

        return [
            'documentation' => $this->text($type['documentation'] ?? ''),
            'parameters' => $fields,
        ];
    }

    /**
     * Forget whether the service metadata could be built: the next request asks again
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->servicesLoaded = null;
    }

    /**
     * Whether Magento's service metadata is built, building it on the first call of the request
     *
     * @return bool
     */
    private function servicesLoaded(): bool
    {
        if ($this->servicesLoaded !== null) {
            return $this->servicesLoaded;
        }
        if ($this->cache->load(self::FAILED_BUILD_KEY) !== false) {
            return $this->servicesLoaded = false;
        }

        try {
            $this->serviceMetadata->getServicesConfig();

            return $this->servicesLoaded = true;
        } catch (\LogicException | \RuntimeException | \ReflectionException $exception) {
            $this->logger->warning(
                'MCP reads the Web API metadata one service at a time: Magento could not build it for all services: '
                . $exception->getMessage(),
                ['exception' => $exception]
            );
            $this->cache->save('1', self::FAILED_BUILD_KEY, [McpCache::SCHEMA_TAG], self::FAILED_BUILD_LIFETIME);

            return $this->servicesLoaded = false;
        }
    }

    /**
     * The method's entry in the built service metadata, or null when the metadata does not hold it
     *
     * @param string $serviceClass
     * @param string $serviceMethod
     * @param string $version
     * @return array<mixed>|null
     */
    private function fromServiceMetadata(string $serviceClass, string $serviceMethod, string $version): ?array
    {
        try {
            $service = $this->serviceMetadata->getServiceMetadata(
                $this->serviceMetadata->getServiceName($serviceClass, $version)
            );
        } catch (\LogicException | \RuntimeException) {
            return null;
        }

        $methods = $service[ServiceMetadata::KEY_SERVICE_METHODS] ?? null;
        if (!is_array($methods)) {
            return null;
        }

        foreach ($methods as $method) {
            if (is_array($method) && ($method[ServiceMetadata::KEY_METHOD] ?? null) === $serviceMethod) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The method's metadata read from its own service class, or null when that class cannot be read
     *
     * @param string $serviceClass
     * @param string $serviceMethod
     * @return array<mixed>|null
     */
    private function fromReflection(string $serviceClass, string $serviceMethod): ?array
    {
        try {
            $methods = $this->classReflector->reflectClassMethods($serviceClass, [$serviceMethod]);
        } catch (\Exception) {
            // The type processor throws a \LogicException for a docblock it cannot read, which the reflector
            // does not declare.
            return null;
        }

        $method = $methods[$serviceMethod] ?? null;

        return is_array($method) ? $method : null;
    }

    /**
     * Parameters or fields in a typed shape; one without a type takes anything
     *
     * @param mixed $parameters
     * @return array<string, array{type: string, required: bool, documentation: string, default?: mixed}>
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
            $type = $parameter['type'] ?? null;
            $entry = [
                'type' => is_string($type) && $type !== '' ? $type : self::ANY_TYPE,
                'required' => (bool) ($parameter['required'] ?? false),
                'documentation' => $this->text($parameter['documentation'] ?? ''),
            ];
            if (array_key_exists('default', $parameter)) {
                $entry['default'] = $parameter['default'];
            }
            $result[(string) $name] = $entry;
        }

        return $result;
    }

    /**
     * A documentation value as trimmed text
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
