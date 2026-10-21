<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * What calling an ability does to the store's data
 *
 * Only "read" leaves the data as it was; read-only mode blocks the other two.
 *
 * @api
 */
enum AbilityAccess: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';

    /**
     * Whether calling the ability leaves the store's data unchanged
     *
     * @return bool
     */
    public function isReadOnly(): bool
    {
        return $this === self::Read;
    }
}
