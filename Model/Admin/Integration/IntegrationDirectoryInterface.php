<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

/**
 * The store's integrations as the MCP Server page lists them: id, name and whether an MCP client can use each one
 *
 * A listing never carries a token. The readiness it states is for display; whatever hands out a token checks it
 * again for that one integration.
 *
 * @phpstan-type IntegrationRow array{id: int, name: string, readiness: IntegrationReadiness}
 */
interface IntegrationDirectoryInterface
{
    /**
     * Every integration of the store, sorted by name regardless of case
     *
     * @return list<IntegrationRow>
     */
    public function all(): array;

    /**
     * The readiness of one integration, or null when there is no integration with this id
     *
     * @param int $integrationId
     * @return IntegrationReadiness|null
     */
    public function readinessOf(int $integrationId): ?IntegrationReadiness;
}
