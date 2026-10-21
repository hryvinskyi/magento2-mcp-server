<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol;

use Hryvinskyi\McpServer\Model\Protocol\Exception\RequestRejectedException;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * The MCP protocol over JSON-RPC 2.0, free of any transport: a request body and what the transport knows go in, a
 * ProtocolAnswer comes out
 *
 * - A body longer than `maxBodyBytes` (a di.xml argument) is refused as a whole before it is decoded, with a bad
 *   request and an Invalid Request error whose id is null.
 * - A body that cannot be read as JSON-RPC is refused as a whole with a bad request and an error whose id is null.
 * - `initialize`, sent alone, needs no session and opens one; the answer announces its id.
 * - Every other single message needs the caller's own live session (see SessionGate). A session in use is recorded
 *   as seen. When looking up or recording the session fails, the failure is logged and a request is answered with an
 *   Internal error that keeps its id, so the client can tell which request failed.
 * - A batch is handled message by message, in order, and answered with a JSON array of the responses to its requests.
 *   A body that holds only notifications and client responses is accepted and answered with nothing.
 * - `initialize` inside a batch is answered on its own with an Invalid Request error that keeps its id, since opening
 *   a session must be the only thing a body does. It is answered before the session is checked, so a batch sent
 *   without a session still tells the client why: when the batch's other messages name no live session of the
 *   caller's, each of its other requests is answered with the session error and the batch gets that error's status.
 * - Ending a session needs the same checks as using it, and is answered with no content.
 */
class Server
{
    private const INITIALIZE = 'initialize';

    /**
     * @param MessageParser $parser
     * @param SessionGate $gate
     * @param SessionStoreInterface $sessions
     * @param Dispatcher $dispatcher
     * @param CallContextFactory $callContextFactory
     * @param ReplyFactory $replyFactory
     * @param JsonRpcResponses $responses
     * @param Answers $answers
     * @param LoggerInterface $logger
     * @param int $maxBodyBytes The longest body accepted, in bytes, at least 1
     * @throws \InvalidArgumentException When the body limit is below 1
     */
    public function __construct(
        private readonly MessageParser $parser,
        private readonly SessionGate $gate,
        private readonly SessionStoreInterface $sessions,
        private readonly Dispatcher $dispatcher,
        private readonly CallContextFactory $callContextFactory,
        private readonly ReplyFactory $replyFactory,
        private readonly JsonRpcResponses $responses,
        private readonly Answers $answers,
        private readonly LoggerInterface $logger,
        private readonly int $maxBodyBytes = 1048576
    ) {
        if ($maxBodyBytes < 1) {
            throw new \InvalidArgumentException('The body limit is at least 1 byte.');
        }
    }

    /**
     * The answer to one request body
     *
     * @param string $body The raw body, as the client sent it
     * @param TransportContext $context
     * @return ProtocolAnswer
     */
    public function handle(string $body, TransportContext $context): ProtocolAnswer
    {
        if (strlen($body) > $this->maxBodyBytes) {
            return $this->answers->error(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('The request body is too large.')->render()
            );
        }

        try {
            $parsed = $this->parser->parse($body);
            if ($parsed->isBatch()) {
                return $this->handleBatch($parsed->getMessages(), $context);
            }

            return $this->handleOne($parsed->getMessages()[0], $context);
        } catch (RequestRejectedException $rejection) {
            return $this->answers->rejection($rejection);
        }
    }

    /**
     * End the caller's session that the request names
     *
     * @param TransportContext $context
     * @return ProtocolAnswer
     */
    public function endSession(TransportContext $context): ProtocolAnswer
    {
        try {
            $this->sessions->delete($this->gate->admit($context)->getId());
        } catch (RequestRejectedException $rejection) {
            return $this->answers->rejection($rejection);
        }

        return $this->answers->noContent();
    }

    /**
     * Answer a body that holds one message
     *
     * @param Message $message
     * @param TransportContext $context
     * @return ProtocolAnswer
     * @throws RequestRejectedException When the message needs a session and names no live session of the caller's
     */
    private function handleOne(Message $message, TransportContext $context): ProtocolAnswer
    {
        if ($this->isInitialize($message)) {
            return $this->answerOne($message, $this->callContextFactory->create(['transport' => $context]));
        }

        try {
            $callContext = $this->openedContext($context);
        } catch (RequestRejectedException $rejection) {
            throw $rejection;
        } catch (\Throwable $failure) {
            $this->logger->error(
                sprintf('The MCP session could not be checked: %s', $failure->getMessage()),
                ['exception' => $failure]
            );

            return $this->answers->response(
                $this->responses->error($message->getId(), ErrorCode::InternalError, __('Internal error')->render())
            );
        }

        return $this->answerOne($message, $callContext);
    }

    /**
     * Answer the messages of a batch, in order, against the one session the batch names
     *
     * @param list<Message> $messages
     * @param TransportContext $context
     * @return ProtocolAnswer
     * @throws RequestRejectedException When the batch names no live session of the caller's and holds no
     *     `initialize` to answer
     */
    private function handleBatch(array $messages, TransportContext $context): ProtocolAnswer
    {
        $callContext = $this->callContextFactory->create(['transport' => $context]);
        $refusal = null;
        if ($this->anyNeedsSession($messages)) {
            try {
                $callContext = $this->openedContext($context);
            } catch (RequestRejectedException $rejection) {
                if (!$this->anyIsInitialize($messages)) {
                    throw $rejection;
                }
                $refusal = $rejection;
            }
        }

        $responses = [];
        foreach ($messages as $message) {
            $reply = $this->answerInBatch($message, $callContext, $refusal);
            if ($reply !== null) {
                $responses[] = $this->responses->of($message->getId(), $reply);
            }
        }

        if ($responses === []) {
            return $this->answers->accepted();
        }

        return $this->answers->batch($responses, $refusal?->getStatus() ?? AnswerStatus::Ok);
    }

    /**
     * The reply to one message of a batch, or null when it is not answered
     *
     * @param Message $message
     * @param CallContext $callContext The batch's session, or none when no message needs one or it was refused
     * @param RequestRejectedException|null $refusal Why the batch's session was refused, or null
     * @return Reply|null
     */
    private function answerInBatch(
        Message $message,
        CallContext $callContext,
        ?RequestRejectedException $refusal
    ): ?Reply {
        if ($this->isInitialize($message)) {
            return $this->replyFactory->create([
                'errorCode' => ErrorCode::InvalidRequest,
                'errorMessage' => __('Invalid Request: initialize must be sent alone, not in a batch.')->render(),
            ]);
        }

        if ($refusal === null || !$this->needsSession($message)) {
            return $this->dispatcher->answer($message, $callContext);
        }

        if ($message->getKind() !== MessageKind::Request) {
            return null;
        }

        return $this->replyFactory->create([
            'errorCode' => $refusal->getErrorCode(),
            'errorMessage' => $refusal->getMessage(),
        ]);
    }

    /**
     * Answer one message
     *
     * @param Message $message
     * @param CallContext $callContext
     * @return ProtocolAnswer
     */
    private function answerOne(Message $message, CallContext $callContext): ProtocolAnswer
    {
        $reply = $this->dispatcher->answer($message, $callContext);
        if ($reply === null) {
            return $this->answers->accepted();
        }

        return $this->answers->response($this->responses->of($message->getId(), $reply), $reply->getSessionId());
    }

    /**
     * The call context of a message that needs the caller's live session, recording the session as seen
     *
     * @param TransportContext $context
     * @return CallContext
     * @throws RequestRejectedException When the request names no live session of the caller's
     */
    private function openedContext(TransportContext $context): CallContext
    {
        $session = $this->gate->admit($context);
        $this->sessions->touch($session);

        return $this->callContextFactory->create(['transport' => $context, 'session' => $session]);
    }

    /**
     * Whether any message of the batch needs the caller's session
     *
     * @param list<Message> $messages
     * @return bool
     */
    private function anyNeedsSession(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($this->needsSession($message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any message of the batch is the request that opens a session
     *
     * @param list<Message> $messages
     * @return bool
     */
    private function anyIsInitialize(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($this->isInitialize($message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether handling the message needs the caller's session: everything but `initialize` and an invalid element
     *
     * @param Message $message
     * @return bool
     */
    private function needsSession(Message $message): bool
    {
        return $message->getKind() !== MessageKind::Invalid && !$this->isInitialize($message);
    }

    /**
     * Whether the message is the request that opens a session
     *
     * @param Message $message
     * @return bool
     */
    private function isInitialize(Message $message): bool
    {
        return $message->getKind() === MessageKind::Request && $message->getMethod() === self::INITIALIZE;
    }
}
