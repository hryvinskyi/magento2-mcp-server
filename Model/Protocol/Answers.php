<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\Exception\RequestRejectedException;

/**
 * Turns what the server decided into a ProtocolAnswer, with every body written by the one JSON encoder
 *
 * A transport uses error() for the refusals it decides itself, before the server runs (a disabled server, a refused
 * caller, a failure nothing else caught), so those bodies are written the same way as the server's.
 */
class Answers
{
    /**
     * @param ProtocolAnswerFactory $answerFactory
     * @param JsonRpcResponses $responses
     * @param JsonEncoder $encoder
     */
    public function __construct(
        private readonly ProtocolAnswerFactory $answerFactory,
        private readonly JsonRpcResponses $responses,
        private readonly JsonEncoder $encoder
    ) {
    }

    /**
     * A JSON-RPC error that answers no request it could read, so its id is null
     *
     * @param AnswerStatus $status
     * @param ErrorCode $code
     * @param string $message
     * @return ProtocolAnswer
     */
    public function error(AnswerStatus $status, ErrorCode $code, string $message): ProtocolAnswer
    {
        return $this->answerFactory->create([
            'status' => $status,
            'body' => $this->encoder->encode($this->responses->error(null, $code, $message)),
        ]);
    }

    /**
     * The answer to a body the server refused as a whole
     *
     * @param RequestRejectedException $rejection
     * @return ProtocolAnswer
     */
    public function rejection(RequestRejectedException $rejection): ProtocolAnswer
    {
        return $this->error($rejection->getStatus(), $rejection->getErrorCode(), $rejection->getMessage());
    }

    /**
     * One JSON-RPC response, announcing the session it opened when there is one
     *
     * @param array<string,mixed> $response
     * @param string $sessionId
     * @return ProtocolAnswer
     */
    public function response(array $response, string $sessionId = ''): ProtocolAnswer
    {
        return $this->answerFactory->create([
            'status' => AnswerStatus::Ok,
            'body' => $this->encoder->encode($response),
            'sessionId' => $sessionId,
        ]);
    }

    /**
     * The responses to the requests of a batch, as one JSON array
     *
     * @param list<array<string,mixed>> $responses
     * @param AnswerStatus $status Ok, unless the batch as a whole was refused while some of it is still answered
     * @return ProtocolAnswer
     */
    public function batch(array $responses, AnswerStatus $status = AnswerStatus::Ok): ProtocolAnswer
    {
        return $this->answerFactory->create([
            'status' => $status,
            'body' => $this->encoder->encode($responses),
        ]);
    }

    /**
     * The messages were taken, and nothing answers them
     *
     * @return ProtocolAnswer
     */
    public function accepted(): ProtocolAnswer
    {
        return $this->answerFactory->create(['status' => AnswerStatus::Accepted]);
    }

    /**
     * The request was done, and nothing answers it
     *
     * @return ProtocolAnswer
     */
    public function noContent(): ProtocolAnswer
    {
        return $this->answerFactory->create(['status' => AnswerStatus::NoContent]);
    }
}
