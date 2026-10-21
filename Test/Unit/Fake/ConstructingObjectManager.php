<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Framework\ObjectManagerInterface;

/**
 * Builds the class preferred for an interface from named constructor arguments, as Magento's object manager does
 * for a generated factory, so unit tests can use the real generated factories
 */
class ConstructingObjectManager implements ObjectManagerInterface
{
    /**
     * @param array<string, class-string> $preferences Concrete class by requested type
     */
    public function __construct(
        private readonly array $preferences
    ) {
    }

    /**
     * Build the preferred class with these named constructor arguments
     *
     * @param string $type
     * @param array<string, mixed> $arguments
     * @return object
     */
    public function create($type, array $arguments = [])
    {
        $class = $this->preferences[ltrim($type, '\\')] ?? null;
        if ($class === null) {
            throw new \LogicException(sprintf('No preference for %s.', $type));
        }

        return new $class(...$arguments);
    }

    /**
     * Refuse: a unit test builds what it needs
     *
     * @param string $type
     * @return never
     */
    public function get($type)
    {
        throw new \LogicException(sprintf('Unit tests ask for no shared %s.', $type));
    }

    /**
     * Ignore configuration: preferences come from the constructor
     *
     * @param array<mixed> $configuration
     * @return void
     */
    public function configure(array $configuration)
    {
    }
}
