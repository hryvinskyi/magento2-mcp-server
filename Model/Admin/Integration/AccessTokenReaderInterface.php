<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

/**
 * The access token of an integration's OAuth consumer, when it has one the MCP endpoint would accept
 *
 * Usable means: an access token exists, its value is not empty, and it is not revoked. The endpoint refuses a
 * revoked token, so a revoked one counts as none.
 */
interface AccessTokenReaderInterface
{
    /**
     * The usable access token of the consumer, or null when it has none
     *
     * @param int $consumerId
     * @return string|null
     */
    public function usableTokenOf(int $consumerId): ?string;
}
