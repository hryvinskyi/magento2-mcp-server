<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * The MCP protocol versions the server speaks, newest first, from the di.xml argument `versions`
 *
 * `initialize` answers with the version the client asked for when the server speaks it, and with the newest one
 * otherwise; the client then decides whether it can go on.
 */
class ProtocolVersions
{
    /**
     * @var list<string>
     */
    private readonly array $versions;

    /**
     * @param array<array-key,string|null> $versions Versions keyed by any name, newest first; null drops one
     * @throws \InvalidArgumentException When no version is left
     */
    public function __construct(array $versions = [])
    {
        $this->versions = array_values(
            array_filter($versions, static fn (?string $version): bool => $version !== null && $version !== '')
        );
        if ($this->versions === []) {
            throw new \InvalidArgumentException('The MCP server speaks at least one protocol version.');
        }
    }

    /**
     * Whether the server speaks this version
     *
     * @param string $version
     * @return bool
     */
    public function supports(string $version): bool
    {
        return in_array($version, $this->versions, true);
    }

    /**
     * The version to answer a client that asked for this one
     *
     * @param string $requested
     * @return string
     */
    public function negotiate(string $requested): string
    {
        return $this->supports($requested) ? $requested : $this->versions[0];
    }

    /**
     * Every version the server speaks, newest first
     *
     * @return list<string>
     */
    public function all(): array
    {
        return $this->versions;
    }
}
