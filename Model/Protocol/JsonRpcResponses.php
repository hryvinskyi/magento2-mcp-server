<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * Builds JSON-RPC 2.0 response objects, ready for the encoder
 *
 * A response always echoes the id of the request it answers; an error that answers no request it could read carries
 * the id null.
 */
class JsonRpcResponses
{
    private const VERSION = '2.0';

    /**
     * The response that answers the request with this id by this reply
     *
     * @param int|string|null $id
     * @param Reply $reply
     * @return array<string, mixed>
     */
    public function of(int|string|null $id, Reply $reply): array
    {
        $code = $reply->getErrorCode();
        if ($code !== null) {
            return $this->error($id, $code, $reply->getErrorMessage(), $reply->getErrorData());
        }

        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $reply->getResult()];
    }

    /**
     * An error response
     *
     * @param int|string|null $id
     * @param ErrorCode $code
     * @param string $message
     * @param array<string,mixed>|null $data The error's `data` member, or null for none
     * @return array<string, mixed>
     */
    public function error(int|string|null $id, ErrorCode $code, string $message, ?array $data = null): array
    {
        return $this->errorWithCode($id, $code->value, $message, $data);
    }

    /**
     * An error response with any integer code, for an error passed on as it came rather than decided by the server
     *
     * @param int|string|null $id
     * @param int $code
     * @param string $message
     * @param array<string,mixed>|\stdClass|null $data The error's `data` member, or null for none; an \stdClass keeps
     *     a JSON object a JSON object when it is empty
     * @return array<string, mixed>
     */
    public function errorWithCode(
        int|string|null $id,
        int $code,
        string $message,
        array|\stdClass|null $data = null
    ): array {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $error];
    }
}
