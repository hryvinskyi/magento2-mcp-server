<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Console\Command\StdioMessageCommand;
use Hryvinskyi\McpServer\Model\Protocol\Answers;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Server;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Hryvinskyi\McpServer\Model\Protocol\TransportContextFactory;
use Hryvinskyi\McpServer\Model\Transport\ChannelSecurityInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One stdio message, in the process the serving loop started for it, once the Web API area is loaded
 *
 * 1. The current store becomes the admin store, the scope of `/rest/all`.
 * 2. The caller is admitted (see CallerAdmission). A refusal writes its reason to the error output, nothing to the
 *    output, and exits with the message command's refusal code, the one code that stops the serving loop.
 * 3. The channel is marked a local pipe, so Web API routes that need a secure channel run.
 * 4. With the end-session flag, the session is ended and the process exits 0 writing nothing.
 * 5. Otherwise the message is read from the input, bounded. An empty input (the serving loop's startup check) writes
 *    nothing and exits 0. An input over the limit is answered with an Invalid Request error. Anything else goes to the
 *    protocol server as the body of a `stdio` request with no protocol version header, and the answer is written to
 *    the output as one envelope line (see Envelope). Exit 0.
 *
 * The envelope reports the session lost when the message named a session and the server answered that it knows no
 * such live session of the caller's.
 *
 * Any other failure, while setting the store or admitting the caller as much as afterwards, is logged and answered in
 * the envelope with an Internal error whose id is null, also when ending the session; the process exits 0, since the
 * caller was not refused. Only a refusal may stop the serving loop: a momentary failure, such as a lost database
 * connection, fails this one message.
 */
class MessageHandler
{
    public const TRANSPORT = 'stdio';

    private const EXIT_SUCCESS = 0;

    /**
     * @param StoreManagerInterface $storeManager
     * @param CallerAdmission $callerAdmission
     * @param ChannelSecurityInterface $channelSecurity
     * @param Server $server
     * @param TransportContextFactory $transportContextFactory
     * @param Answers $answers
     * @param StdioStreams $streams
     * @param Envelope $envelope
     * @param LoggerInterface $logger
     * @param int $maxMessageBytes The longest message accepted, in bytes
     * @throws \InvalidArgumentException When the message limit is below 1
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CallerAdmission $callerAdmission,
        private readonly ChannelSecurityInterface $channelSecurity,
        private readonly Server $server,
        private readonly TransportContextFactory $transportContextFactory,
        private readonly Answers $answers,
        private readonly StdioStreams $streams,
        private readonly Envelope $envelope,
        private readonly LoggerInterface $logger,
        private readonly int $maxMessageBytes = 1048576
    ) {
        if ($maxMessageBytes < 1) {
            throw new \InvalidArgumentException('The message limit is at least 1 byte.');
        }
    }

    /**
     * Handle the message on the input, or end the session, as the admin with this login, and return the exit code
     *
     * @param string $login The admin's login
     * @param string $sessionId The session the serving loop knows, or ''
     * @param bool $endSession Whether to end the session instead of handling a message
     * @param resource|null $input The stream the message is read from; standard input when null
     * @param resource|null $output The stream the envelope goes to; standard output when null
     * @param resource|null $errorOutput The stream a refusal goes to; standard error when null
     * @return int 0 when the caller was served, the message command's refusal code when refused
     * @throws FileSystemException When a stream cannot be opened or written
     */
    public function handle(
        string $login,
        string $sessionId,
        bool $endSession,
        mixed $input = null,
        mixed $output = null,
        mixed $errorOutput = null
    ): int {
        try {
            $this->storeManager->setCurrentStore(Store::ADMIN_CODE);
            $caller = $this->callerAdmission->admit($login);
        } catch (CallerRefusedException $refusal) {
            $this->streams->write($errorOutput ?? $this->streams->standardError(), $refusal->getMessage() . "\n");

            return StdioMessageCommand::EXIT_REFUSED;
        } catch (\Throwable $failure) {
            $this->writeEnvelope($output, $this->failed($failure), false);

            return self::EXIT_SUCCESS;
        }

        $answer = $this->served($caller, $sessionId, $endSession, $input);
        if ($answer !== null) {
            $this->writeEnvelope(
                $output,
                $answer,
                $sessionId !== '' && $answer->getStatus() === AnswerStatus::NotFound
            );
        }

        return self::EXIT_SUCCESS;
    }

    /**
     * Serve the admitted caller: end the session, or answer the message on the input; null when nothing is answered
     *
     * @param CallerInterface $caller
     * @param string $sessionId
     * @param bool $endSession
     * @param resource|null $input
     * @return ProtocolAnswer|null
     */
    private function served(
        CallerInterface $caller,
        string $sessionId,
        bool $endSession,
        mixed $input
    ): ?ProtocolAnswer {
        try {
            $this->channelSecurity->markLocalChannel();
            $context = $this->transportContextFactory->create([
                'caller' => $caller,
                'transport' => self::TRANSPORT,
                'sessionId' => $sessionId,
            ]);

            if ($endSession) {
                $this->server->endSession($context);

                return null;
            }

            return $this->answerTo($input ?? $this->streams->standardInput(), $context);
        } catch (\Throwable $failure) {
            return $this->failed($failure);
        }
    }

    /**
     * Log a failure and give the Internal error that answers it, with a null id
     *
     * @param \Throwable $failure
     * @return ProtocolAnswer
     */
    private function failed(\Throwable $failure): ProtocolAnswer
    {
        $this->logger->error(
            sprintf('The MCP stdio message failed: %s', $failure->getMessage()),
            ['exception' => $failure]
        );

        return $this->answers->error(AnswerStatus::Ok, ErrorCode::InternalError, __('Internal error')->render());
    }

    /**
     * Write the answer to the output as one envelope line
     *
     * @param resource|null $output The stream the envelope goes to; standard output when null
     * @param ProtocolAnswer $answer
     * @param bool $sessionLost Whether the session the message named is no longer a live session of the caller's
     * @return void
     * @throws FileSystemException When the output cannot be opened or written
     */
    private function writeEnvelope(mixed $output, ProtocolAnswer $answer, bool $sessionLost): void
    {
        $this->streams->write(
            $output ?? $this->streams->standardOutput(),
            $this->envelope->encode($answer->getBody(), $answer->getSessionId(), $sessionLost)
        );
    }

    /**
     * The protocol's answer to the message on the input, or null when the input is empty
     *
     * @param resource $input
     * @param TransportContext $context
     * @return ProtocolAnswer|null
     * @throws FileSystemException When the input cannot be read
     */
    private function answerTo(mixed $input, TransportContext $context): ?ProtocolAnswer
    {
        $message = $this->streams->readAll($input, $this->maxMessageBytes);
        if ($message === '') {
            return null;
        }

        if (strlen($message) > $this->maxMessageBytes) {
            return $this->answers->error(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('The message is too large.')->render()
            );
        }

        return $this->server->handle($message, $context);
    }
}
