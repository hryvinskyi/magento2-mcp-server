<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Http;

/**
 * The host an absolute URL names
 *
 * Every part of the module that compares or prints a URL's host reads it here, so they all agree on what the host of
 * an unusual URL is: user info is skipped, a bracketed IPv6 address is a host, and a URL without a scheme has none.
 */
class UrlHost
{
    /**
     * The host of an absolute URL: after the scheme and any user info, up to the port, path, query or fragment
     */
    private const HOST_PATTERN = '~^[a-z][a-z0-9+.\-]*://(?:[^@/?#]*@)?'
        . '(\[[0-9a-f:.]+\]|[^:/?#@\[\]\s]+)(?::\d*)?(?:[/?#]|$)~i';

    /**
     * The lowercase host of a URL, or null when it names none
     *
     * Surrounding whitespace is ignored. The scheme is required; user info, the port, the path, the query and the
     * fragment are not part of the host. A bracketed IPv6 address keeps its brackets.
     *
     * @param string $url
     * @return string|null
     */
    public function of(string $url): ?string
    {
        return preg_match(self::HOST_PATTERN, trim($url), $matches) === 1 ? strtolower($matches[1]) : null;
    }
}
