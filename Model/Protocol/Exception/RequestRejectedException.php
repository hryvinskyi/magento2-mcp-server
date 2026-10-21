<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Exception;

use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;

/**
 * The whole body is refused before any message in it is handled
 *
 * It is answered with the status it names and one JSON-RPC error whose id is null, since no request id is answered.
 */
class RequestRejectedException extends \RuntimeException
{
    /**
     * @param AnswerStatus $status
     * @param ErrorCode $errorCode
     * @param string $message Meant for the client
     */
    public function __construct(
        private readonly AnswerStatus $status,
        private readonly ErrorCode $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    /**
     * The kind of answer the refusal gets
     *
     * @return AnswerStatus
     */
    public function getStatus(): AnswerStatus
    {
        return $this->status;
    }

    /**
     * The JSON-RPC error code of the answer
     *
     * @return ErrorCode
     */
    public function getErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }
}
