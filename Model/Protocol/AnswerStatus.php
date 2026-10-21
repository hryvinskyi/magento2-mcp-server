<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

/**
 * What kind of answer the protocol gives, in terms any transport can translate into its own
 *
 * - ok: the body holds the JSON-RPC answer;
 * - accepted: the messages were taken and nothing is answered;
 * - no_content: the request was done and nothing is answered;
 * - bad_request: the input cannot be accepted (invalid JSON, envelope or batch, a missing session id, a protocol
 *   version the server or the session does not speak);
 * - not_found: the session is unknown, expired or someone else's;
 * - forbidden: the transport refuses the caller.
 */
enum AnswerStatus: string
{
    case Ok = 'ok';
    case Accepted = 'accepted';
    case NoContent = 'no_content';
    case BadRequest = 'bad_request';
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';

    /**
     * Whether an answer of this kind may carry a body
     *
     * @return bool
     */
    public function allowsBody(): bool
    {
        return $this !== self::Accepted && $this !== self::NoContent;
    }
}
