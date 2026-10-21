<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Exception;

use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;

/**
 * One request is answered with a JSON-RPC error instead of a result
 */
class JsonRpcErrorException extends \RuntimeException
{
    /**
     * @param ErrorCode $errorCode
     * @param string $message Meant for the client
     * @param array<string,mixed>|null $data The error's `data` member, or null for none
     */
    public function __construct(
        private readonly ErrorCode $errorCode,
        string $message,
        private readonly ?array $data = null
    ) {
        parent::__construct($message);
    }

    /**
     * The JSON-RPC error code
     *
     * @return ErrorCode
     */
    public function getErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    /**
     * The error's `data` member, or null for none
     *
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }
}
