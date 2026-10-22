<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

/**
 * Whether a URL may be the GraphQL endpoint the server calls
 *
 * Only an absolute `http` or `https` URL whose path ends in `/graphql` is accepted:
 * - no other scheme, so the request can never go to a file, FTP or another protocol the HTTP client knows;
 * - no user info, which the HTTP client would turn into an `Authorization` header, while the calls are a guest's;
 * - no query string or fragment, and no whitespace or control character anywhere, so nothing can be smuggled into the
 *   request line.
 *
 * The host is a name (letters, digits, `.`, `-`, `_`) or a bracketed IPv6 address, with an optional port.
 */
class EndpointUrlValidator
{
    private const PATTERN = '~^https?://'
        . '(?:[a-z0-9](?:[a-z0-9_.\-]*[a-z0-9])?|\[[0-9a-f:.]+\])'
        . '(?::[0-9]{1,5})?'
        . '(?:/[^?#\s\x00-\x1f\x7f]*)?/graphql$~iD';

    /**
     * Whether the URL is an absolute http(s) URL whose path ends in `/graphql`
     *
     * @param string $url
     * @return bool
     */
    public function isValid(string $url): bool
    {
        return preg_match(self::PATTERN, $url) === 1;
    }
}
