<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Http;

use Magento\Framework\Controller\ResultInterface;

/**
 * The JSON answer of an admin route that a script of the MCP Server page calls
 *
 * The answer is one no cache keeps (`Cache-Control: no-store`, `Pragma: no-cache`) and no browser reads as anything
 * but JSON (`X-Content-Type-Options: nosniff`), whatever the body holds: a token, a section's state or an error.
 */
interface NoStoreJsonInterface
{
    /**
     * A JSON result with this status and body that no cache keeps and no browser sniffs
     *
     * @param int $status HTTP status code
     * @param array<string,mixed> $body Encoded as the JSON object of the answer
     * @return ResultInterface
     */
    public function answer(int $status, array $body): ResultInterface;
}
