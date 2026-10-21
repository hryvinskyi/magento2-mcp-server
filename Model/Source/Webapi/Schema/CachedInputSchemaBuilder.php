<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi\Schema;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;
use Magento\Framework\Cache\FrontendInterface;

/**
 * Keeps built schemas in the module's cache type, in front of the builder that reads Magento's Web API metadata
 *
 * - The key holds the ability name only: a schema depends on nothing else. The entries carry the tag
 *   `HRYVINSKYI_MCP_SCHEMA` besides the cache type's own, and have no lifetime: the cache type is cleaned with every
 *   deployment (`setup:upgrade`, `cache:flush`), the same moments that refresh the Web API metadata the schemas come
 *   from.
 * - While the cache type is disabled, nothing is kept and every read builds the schema again, so the schemas stay
 *   right; only slower.
 * - A schema the builder marks as a fallback (the Web API could not describe the method or one of its types) is
 *   returned but never kept, so the real schema is built as soon as the metadata can give it.
 * - An entry is JSON. Empty JSON objects come back as empty \stdClass values and every other object as an array, the
 *   form the builder returns, so a cached schema encodes exactly as a fresh one. An entry that cannot be read is
 *   built again.
 */
class CachedInputSchemaBuilder implements InputSchemaBuilderInterface
{
    /**
     * Tag of every schema entry
     */
    public const CACHE_TAG = McpCache::SCHEMA_TAG;

    private const KEY_PREFIX = 'hryvinskyi_mcp_schema_';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param InputSchemaBuilderInterface $inner Builder that reads the Web API metadata
     * @param FrontendInterface $cache The module's cache type
     * @param InputSchemaFactory $schemaFactory
     */
    public function __construct(
        private readonly InputSchemaBuilderInterface $inner,
        private readonly FrontendInterface $cache,
        private readonly InputSchemaFactory $schemaFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(AbilityInterface $ability, Route $route): InputSchema
    {
        $key = $this->keyOf($ability);
        $entry = $this->cache->load($key);
        $cached = is_string($entry) ? $this->decode($entry) : null;
        if ($cached !== null) {
            return $this->schemaFactory->create()->withSchema($cached);
        }

        $schema = $this->inner->build($ability, $route);
        if (!$schema->isFallback()) {
            $this->cache->save(json_encode($schema->getSchema(), self::JSON_FLAGS), $key, [self::CACHE_TAG]);
        }

        return $schema;
    }

    /**
     * Cache key of an ability's schema
     *
     * Cache ids allow letters, digits and `_` only, and other characters collapse into `_`, so the name is hashed
     * rather than written out: `a.b_c` and `a_b.c` keep apart.
     *
     * @param AbilityInterface $ability
     * @return string
     */
    private function keyOf(AbilityInterface $ability): string
    {
        return self::KEY_PREFIX . sha1($ability->getName());
    }

    /**
     * The schema an entry holds, or null for an entry that is not a JSON object
     *
     * @param string $entry
     * @return array<string, mixed>|null
     */
    private function decode(string $entry): ?array
    {
        try {
            $decoded = json_decode($entry, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $schema = $this->restore($decoded);
        if (!is_array($schema) || array_is_list($schema)) {
            return null;
        }

        $result = [];
        foreach ($schema as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * A decoded JSON value in the builder's form: objects as arrays, except empty ones, which stay \stdClass
     *
     * @param mixed $value
     * @return mixed
     */
    private function restore(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);

            return $fields === [] ? $value : array_map(fn (mixed $field): mixed => $this->restore($field), $fields);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->restore($item), $value);
        }

        return $value;
    }
}
