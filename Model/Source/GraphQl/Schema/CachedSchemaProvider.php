<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Clock\ClockInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the GraphQL schema, and the memory of a failed read, in front of the provider that introspects the endpoint
 *
 * - **Cache.** The schema is kept in the module's cache type under the endpoint URL and the store code, tagged
 *   `HRYVINSKYI_MCP_SCHEMA`, until the cache type is cleaned: clean it after installing a module that changes the
 *   GraphQL schema. A failed read is remembered there for five minutes, so the endpoint is not asked again meanwhile.
 *   While the cache type is disabled, as a newly added one is after `setup:upgrade`, nothing is kept there.
 * - **In-process memo**, with or without the cache: a schema read once is kept for the rest of the request, so two
 *   catalog reads introspect once; a failure is kept for five minutes of the process by the module's monotonic
 *   clock, so a process does not wait on a failing endpoint again and again. Only the schema is forgotten between
 *   requests; the memory of a failure outlives them.
 * - An endpoint that cannot be named (an invalid URL setting, no store view) gives null and a logged warning.
 * - A cache entry that cannot be read back as a schema is read from the endpoint again.
 */
class CachedSchemaProvider implements SchemaProviderInterface, ResetAfterRequestInterface
{
    /**
     * Tag of the schema entries and of the memory of a failed read
     */
    public const CACHE_TAG = McpCache::SCHEMA_TAG;

    /**
     * How long a failed read is remembered, in seconds
     */
    public const FAILURE_LIFETIME_SECONDS = 300;

    private const KEY_PREFIX = 'hryvinskyi_mcp_graphql_schema_';

    private const FAILURE_KEY_PREFIX = 'hryvinskyi_mcp_graphql_schema_failed_';

    private const MILLISECONDS_PER_SECOND = 1000;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Schemas read during this request, by cache key
     *
     * @var array<string,IntrospectedSchema>
     */
    private array $schemas = [];

    /**
     * Clock reading, in milliseconds, until which a failed read is remembered, by cache key
     *
     * @var array<string,float>
     */
    private array $failedUntil = [];

    /**
     * @param SchemaProviderInterface $inner The provider that introspects the endpoint
     * @param FrontendInterface $cache The module's cache type
     * @param EndpointInterface $endpoint
     * @param SchemaReader $reader
     * @param ClockInterface $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SchemaProviderInterface $inner,
        private readonly FrontendInterface $cache,
        private readonly EndpointInterface $endpoint,
        private readonly SchemaReader $reader,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function schema(): ?IntrospectedSchema
    {
        $key = $this->key();
        if ($key === null) {
            return null;
        }
        if (isset($this->schemas[$key])) {
            return $this->schemas[$key];
        }
        if (($this->failedUntil[$key] ?? 0.0) > $this->clock->nowMs()) {
            return null;
        }
        unset($this->failedUntil[$key]);
        if ($this->cache->load(self::FAILURE_KEY_PREFIX . $key) !== false) {
            return null;
        }

        $schema = $this->cached($key);
        if ($schema === null) {
            $schema = $this->inner->schema();
            if ($schema === null) {
                $this->rememberFailure($key);

                return null;
            }
            $this->keep($key, $schema);
        }

        return $this->schemas[$key] = $schema;
    }

    /**
     * Forget the schemas read during this request; a remembered failure stays
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->schemas = [];
    }

    /**
     * The cache key of the endpoint's schema, or null when the endpoint cannot be named
     *
     * Cache ids allow letters, digits and `_` only, so the URL and store code are hashed.
     *
     * @return string|null
     */
    private function key(): ?string
    {
        try {
            return sha1($this->endpoint->url() . "\n" . $this->endpoint->storeCode());
        } catch (GraphQlTransportException $exception) {
            $this->logger->warning(
                'MCP offers no GraphQL abilities: the GraphQL endpoint cannot be named: ' . $exception->getMessage()
            );

            return null;
        }
    }

    /**
     * The schema the cache holds under this key, or null
     *
     * @param string $key
     * @return IntrospectedSchema|null
     */
    private function cached(string $key): ?IntrospectedSchema
    {
        $entry = $this->cache->load(self::KEY_PREFIX . $key);
        if (!is_string($entry)) {
            return null;
        }

        try {
            $schemaNode = json_decode($entry, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($schemaNode) ? $this->reader->read($schemaNode) : null;
    }

    /**
     * Keep a schema in the cache
     *
     * @param string $key
     * @param IntrospectedSchema $schema
     * @return void
     */
    private function keep(string $key, IntrospectedSchema $schema): void
    {
        try {
            $entry = json_encode($schema->toIntrospection(), self::JSON_FLAGS);
        } catch (\JsonException) {
            return;
        }
        $this->cache->save($entry, self::KEY_PREFIX . $key, [self::CACHE_TAG]);
    }

    /**
     * Remember a failed read for five minutes, in the process and in the cache
     *
     * @param string $key
     * @return void
     */
    private function rememberFailure(string $key): void
    {
        $this->failedUntil[$key] = $this->clock->nowMs()
            + self::FAILURE_LIFETIME_SECONDS * self::MILLISECONDS_PER_SECOND;
        $this->cache->save('1', self::FAILURE_KEY_PREFIX . $key, [self::CACHE_TAG], self::FAILURE_LIFETIME_SECONDS);
    }
}
