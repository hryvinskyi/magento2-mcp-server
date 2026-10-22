<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

/**
 * Sends one GraphQL operation to the store's GraphQL endpoint, as a guest, and returns its answer
 *
 * The answer comes from the network. Like a client's arguments, it never goes into a generated factory's array or
 * `ObjectManager::create()`: a key `instance` or `argument` there builds an object or reads `env.php`.
 */
interface GraphQlClientInterface
{
    /**
     * The decoded answer to this operation
     *
     * Any HTTP status counts as an answer when its body is a JSON object holding `data` or `errors`, since the
     * endpoint answers a syntax error, an authentication or authorization error and a failed resolver with an error
     * status and an `errors` body. JSON objects are decoded as arrays.
     *
     * @param string $document The GraphQL document; arguments never go into its text, only into the variables
     * @param array<string,mixed> $variables Encoded as given; an empty JSON object inside them is an empty \stdClass
     * @return array<string,mixed> The answer, with a `data` key, an `errors` key, or both
     * @throws GraphQlTransportException When no such answer came
     */
    public function request(string $document, array $variables): array;
}
