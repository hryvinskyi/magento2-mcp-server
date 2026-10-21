<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\ClientInterface;

/**
 * Immutable client, created through ClientInterfaceFactory
 *
 * Name and version may be empty, since a client is not obliged to introduce itself, but never longer than the
 * published maximum lengths.
 */
class Client implements ClientInterface
{
    /**
     * @param string $name
     * @param string $version
     * @throws \InvalidArgumentException When the name or the version is too long
     */
    public function __construct(
        private readonly string $name = '',
        private readonly string $version = ''
    ) {
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('A client name is at most %d characters long.', self::NAME_MAX_LENGTH)
            );
        }

        if (mb_strlen($version) > self::VERSION_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('A client version is at most %d characters long.', self::VERSION_MAX_LENGTH)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return $this->version;
    }
}
