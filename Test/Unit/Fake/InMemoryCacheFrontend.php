<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\Cache\FrontendInterface;

/**
 * A cache frontend over an array; switched off, it drops saves and misses loads, as a disabled cache type does
 */
class InMemoryCacheFrontend implements FrontendInterface
{
    /**
     * Entries by identifier, with the tags and lifetime they were saved with
     *
     * @var array<string, array{data: string, tags: list<string>, lifeTime: int|null}>
     */
    public array $entries = [];

    /**
     * @param bool $enabled
     */
    public function __construct(
        private readonly bool $enabled = true
    ) {
    }

    /**
     * @param string $identifier
     * @return int|bool
     */
    public function test($identifier)
    {
        return $this->enabled && isset($this->entries[$identifier]) ? 1 : false;
    }

    /**
     * @param string $identifier
     * @return string|bool
     */
    public function load($identifier)
    {
        return $this->enabled ? ($this->entries[$identifier]['data'] ?? false) : false;
    }

    /**
     * @param string $data
     * @param string $identifier
     * @param array<string> $tags
     * @param int|null $lifeTime
     * @return bool
     */
    public function save($data, $identifier, array $tags = [], $lifeTime = null)
    {
        if (!$this->enabled) {
            return false;
        }
        $this->entries[$identifier] = [
            'data' => $data,
            'tags' => array_values($tags),
            'lifeTime' => is_numeric($lifeTime) ? (int) $lifeTime : null,
        ];

        return true;
    }

    /**
     * @param string $identifier
     * @return bool
     */
    public function remove($identifier)
    {
        unset($this->entries[$identifier]);

        return true;
    }

    /**
     * @param string $mode
     * @param array<string> $tags
     * @return bool
     */
    public function clean($mode = \Magento\Framework\Cache\CacheConstants::CLEANING_MODE_ALL, array $tags = [])
    {
        $this->entries = [];

        return true;
    }

    /**
     * A unit test has no backend
     *
     * @return never
     */
    public function getBackend()
    {
        throw new \LogicException('An in-memory cache has no backend.');
    }

    /**
     * A unit test has no low-level frontend
     *
     * @return never
     */
    public function getLowLevelFrontend()
    {
        throw new \LogicException('An in-memory cache has no low-level frontend.');
    }
}
