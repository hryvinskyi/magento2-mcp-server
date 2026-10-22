<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Cache;

use Hryvinskyi\McpServer\Model\Cache\Type\McpCache;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The module's cache type as Cache Management and the container see it
 */
class McpCacheTest extends TestCase
{
    use BlocksRealGraphQlClient;

    public function testTheTypeIsListedInCacheManagement(): void
    {
        $typeList = Bootstrap::getObjectManager()->get(TypeListInterface::class);
        self::assertInstanceOf(TypeListInterface::class, $typeList);

        $types = $typeList->getTypes();
        self::assertArrayHasKey(McpCache::TYPE_IDENTIFIER, $types);
        $type = $types[McpCache::TYPE_IDENTIFIER];
        self::assertInstanceOf(DataObject::class, $type);
        self::assertSame('MCP Server', $this->text($type->getData('cache_type')));
        self::assertSame('Ability input schemas and the GraphQL schema', $this->text($type->getData('description')));
        self::assertSame(McpCache::CACHE_TAG, $type->getData('tags'));
    }

    /**
     * A disabled type drops every save, so the type is switched on for this test and put back afterwards
     */
    public function testCleaningTheTypeRemovesItsEntries(): void
    {
        $state = Bootstrap::getObjectManager()->get(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);
        $wasEnabled = $state->isEnabled(McpCache::TYPE_IDENTIFIER);
        $state->setEnabled(McpCache::TYPE_IDENTIFIER, true);

        try {
            $cache = Bootstrap::getObjectManager()->get(McpCache::class);
            self::assertInstanceOf(McpCache::class, $cache);
            self::assertSame(McpCache::CACHE_TAG, $cache->getTag());

            self::assertTrue($cache->save('{"type":"object"}', 'hryvinskyi_mcp_test_entry'));
            self::assertSame('{"type":"object"}', $cache->load('hryvinskyi_mcp_test_entry'));

            $cache->clean();

            self::assertFalse($cache->load('hryvinskyi_mcp_test_entry'));
        } finally {
            $state->setEnabled(McpCache::TYPE_IDENTIFIER, $wasEnabled);
        }
    }

    /**
     * A label as text, whether the type list holds it as a string or as a phrase
     *
     * @param mixed $label
     * @return string
     */
    private function text(mixed $label): string
    {
        self::assertTrue(is_string($label) || $label instanceof \Stringable);

        return (string) $label;
    }
}
