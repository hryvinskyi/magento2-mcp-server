<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

/**
 * The GraphQL endpoint gave no GraphQL answer: it could not be reached or resolved, it took too long, it sent too much,
 * or its body was not a JSON object holding `data` or `errors`
 *
 * The message is for the log; it may name hosts and transport details a client must not see.
 */
class GraphQlTransportException extends \RuntimeException
{
}
