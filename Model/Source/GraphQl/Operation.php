<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;

/**
 * The kind of GraphQL operation a root field belongs to; the value is the operation's keyword in a document
 *
 * A query reads the store's data, a mutation may change it.
 */
enum Operation: string
{
    case Query = 'query';
    case Mutation = 'mutation';

    /**
     * What calling a root field of this operation does to the store's data
     *
     * @return AbilityAccess
     */
    public function access(): AbilityAccess
    {
        return match ($this) {
            self::Query => AbilityAccess::Read,
            self::Mutation => AbilityAccess::Write,
        };
    }
}
