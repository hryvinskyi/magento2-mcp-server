<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Hryvinskyi\McpServer\Console\Command\StdioMessageCommand;
use Hryvinskyi\McpServer\Model\Protocol\Answers;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Session\Session;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;

/**
 * The serving side of the stdio transport: reads newline-delimited JSON-RPC from its input and hands every line to a
 * fresh `bin/magento` process of its own, so settings, admin rights and data are read anew for every message
 *
 * - Before reading anything, one message process runs with no message: it performs every check on the admin and the
 *   server. Unless it exits 0 having written nothing, its standard error is copied and the loop exits 1: nothing has
 *   been served yet, so any failure there is the server's answer.
 * - Lines are read bounded. An empty line is skipped. A line over the limit is answered with an Invalid Request error
 *   (id null) without starting a process, and the loop goes on with the next line.
 * - Every other line is the standard input of a new message process, started from an argument list (never a shell
 *   string) with PHP's errors sent to standard error, the admin's login and the session once one is known.
 * - The process answers with one envelope line (see Envelope). Its body, when not empty, is written to the output as
 *   one line; the session id it announces is kept when it is a session id.
 * - A process that exits with the message command's refusal code refused the caller (the admin lost access, the
 *   server was turned off): the loop exits 1 and ends nothing. A process that exits with any other code, runs past
 *   its time limit or writes no valid envelope failed this one message: the line is answered with Internal errors
 *   that keep the ids of its requests (see MessageLines), and the loop goes on.
 * - A lost session is recovered once per line. When the envelope reports the session lost and the loop knows the
 *   `initialize` line that opened it, that line is sent again with no session (followed by the client's
 *   `notifications/initialized` line when it sent one), their answers are dropped, the new session is kept, and the
 *   lost line is sent once more in it. When the session cannot be opened again, or the line finds it lost once more,
 *   the line's original answer is written.
 * - Whatever a process writes to its standard error is copied to the loop's standard error.
 * - At the end of the input, the session, when one is known, is ended by one more process, and the loop exits 0.
 *
 * Nothing but answer bodies is ever written to the output.
 */
class StdioLoop
{
    private const EXIT_SUCCESS = 0;
    private const EXIT_STOPPED = 1;

    /**
     * @param ProcessRunnerInterface $processRunner
     * @param StdioStreams $streams
     * @param Envelope $envelope
     * @param Answers $answers
     * @param MessageLines $messageLines
     * @param ServedSessionFactory $servedSessionFactory
     * @param DirectoryList $directoryList
     * @param int $messageTimeoutSeconds How long one message process may run
     * @param int $maxLineBytes The longest line accepted, in bytes
     * @param string $phpBinary The PHP interpreter that runs the message processes; this process's own when not given
     * @throws \InvalidArgumentException When the time limit or the line limit is below 1
     */
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly StdioStreams $streams,
        private readonly Envelope $envelope,
        private readonly Answers $answers,
        private readonly MessageLines $messageLines,
        private readonly ServedSessionFactory $servedSessionFactory,
        private readonly DirectoryList $directoryList,
        private readonly int $messageTimeoutSeconds = 300,
        private readonly int $maxLineBytes = 1048576,
        private readonly string $phpBinary = PHP_BINARY
    ) {
        if ($messageTimeoutSeconds < 1 || $maxLineBytes < 1) {
            throw new \InvalidArgumentException('The message time limit and the line limit are at least 1.');
        }
    }

    /**
     * Serve the input as this admin until it ends, and return the exit code
     *
     * @param string $login The admin's login
     * @param resource|null $input The stream to read messages from; standard input when null
     * @param resource|null $output The stream answers go to; standard output when null
     * @param resource|null $errorOutput The stream diagnostics go to; standard error when null
     * @return int 0 at the end of the input, 1 when the admin or the server refused
     * @throws FileSystemException When a stream cannot be opened, read or written
     */
    public function run(string $login, mixed $input = null, mixed $output = null, mixed $errorOutput = null): int
    {
        $input ??= $this->streams->standardInput();
        $output ??= $this->streams->standardOutput();
        $errorOutput ??= $this->streams->standardError();

        $startup = $this->processRunner->run($this->command($login, ''), '', $this->messageTimeoutSeconds);
        $this->copyErrorOutput($startup, $errorOutput);
        if ($startup->getExitCode() !== self::EXIT_SUCCESS || $startup->getOutput() !== '') {
            $this->say($errorOutput, $this->describeFailure('The MCP stdio server cannot start', $startup));

            return self::EXIT_STOPPED;
        }

        $session = $this->servedSessionFactory->create();
        try {
            while (($line = $this->streams->readLine($input, $this->maxLineBytes)) !== null) {
                if (strlen($line) > $this->maxLineBytes) {
                    $this->writeLine(
                        $output,
                        $this->answers->error(
                            AnswerStatus::Ok,
                            ErrorCode::InvalidRequest,
                            __('The message is too large.')->render()
                        )->getBody()
                    );
                    continue;
                }

                if (trim($line) !== '') {
                    $this->serve($login, $line, $session, $output, $errorOutput);
                }
            }
        } catch (CallerRefusedException) {
            return self::EXIT_STOPPED;
        }

        if ($session->getId() !== '') {
            $command = $this->command($login, $session->getId());
            $command[] = '--' . StdioMessageCommand::OPTION_END_SESSION;
            $this->copyErrorOutput($this->processRunner->run($command, '', $this->messageTimeoutSeconds), $errorOutput);
        }

        return self::EXIT_SUCCESS;
    }

    /**
     * Answer one line through a message process, recovering a lost session once
     *
     * @param string $login
     * @param string $line
     * @param ServedSession $session
     * @param resource $output
     * @param resource $errorOutput
     * @return void
     * @throws CallerRefusedException When a message process refused the caller
     * @throws FileSystemException When a stream cannot be written
     */
    private function serve(string $login, string $line, ServedSession $session, mixed $output, mixed $errorOutput): void
    {
        $envelope = $this->send($login, $session->getId(), $line, $errorOutput);
        if ($envelope !== null && $envelope['sessionLost']) {
            $envelope = $this->retryInNewSession($login, $line, $session, $envelope, $errorOutput);
        }

        if ($envelope === null) {
            $this->writeLine($output, $this->messageLines->failureAnswer($line));

            return;
        }

        $this->writeLine($output, $envelope['body']);
        if (preg_match(Session::ID_PATTERN, $envelope['sessionId']) === 1) {
            if ($this->messageLines->opensSession($line)) {
                $session->opened($envelope['sessionId'], $line);
            } else {
                $session->adopt($envelope['sessionId']);
            }

            return;
        }

        if (!$envelope['sessionLost'] && $this->messageLines->confirmsSession($line)) {
            $session->confirmed($line);
        }
    }

    /**
     * Open the lost session again the way the client opened it, and send the line once more in the new one
     *
     * @param string $login
     * @param string $line
     * @param ServedSession $session
     * @param array $lost The envelope that reported the loss
     * @phpstan-param array{body: string, sessionId: string, sessionLost: bool} $lost
     * @param resource $errorOutput
     * @return array{body: string, sessionId: string, sessionLost: bool}|null The retry's envelope; the one that
     *     reported the loss when the session cannot be opened again or is lost once more; null when the retry failed
     * @throws CallerRefusedException When a message process refused the caller
     * @throws FileSystemException When the error output cannot be written
     */
    private function retryInNewSession(
        string $login,
        string $line,
        ServedSession $session,
        array $lost,
        mixed $errorOutput
    ): ?array {
        if ($session->getInitializeLine() === '') {
            return $lost;
        }

        if (!$this->reopen($login, $session, $errorOutput)) {
            $this->say(
                $errorOutput,
                'The lost MCP session could not be opened again; the message is answered as lost.'
            );

            return $lost;
        }

        $retried = $this->send($login, $session->getId(), $line, $errorOutput);
        if ($retried !== null && $retried['sessionLost']) {
            $this->say($errorOutput, 'The new MCP session was lost as well; the message is answered as lost.');

            return $lost;
        }

        return $retried;
    }

    /**
     * Open a new session by sending the remembered session-opening lines again
     *
     * The `initialize` line goes with no session, then the `notifications/initialized` line, when the client sent
     * one, in the session it opened. Their answers are dropped.
     *
     * @param string $login
     * @param ServedSession $session
     * @param resource $errorOutput
     * @return bool Whether a new session was opened and, when the client had confirmed the old one, confirmed
     * @throws CallerRefusedException When a message process refused the caller
     * @throws FileSystemException When the error output cannot be written
     */
    private function reopen(string $login, ServedSession $session, mixed $errorOutput): bool
    {
        $opened = $this->send($login, '', $session->getInitializeLine(), $errorOutput);
        if ($opened === null || preg_match(Session::ID_PATTERN, $opened['sessionId']) !== 1) {
            return false;
        }

        $session->adopt($opened['sessionId']);
        $initialized = $session->getInitializedLine();
        if ($initialized === '') {
            return true;
        }

        $confirmed = $this->send($login, $session->getId(), $initialized, $errorOutput);

        return $confirmed !== null && !$confirmed['sessionLost'];
    }

    /**
     * Run a message process for this input, and read its envelope
     *
     * @param string $login
     * @param string $sessionId The session to use, or '' for none
     * @param string $input The line the process reads
     * @param resource $errorOutput
     * @return array{body: string, sessionId: string, sessionLost: bool}|null The envelope, or null when the process
     *     failed (described on the error output)
     * @throws CallerRefusedException When the process refused the caller
     * @throws FileSystemException When the error output cannot be written
     */
    private function send(string $login, string $sessionId, string $input, mixed $errorOutput): ?array
    {
        $result = $this->processRunner->run($this->command($login, $sessionId), $input, $this->messageTimeoutSeconds);
        $this->copyErrorOutput($result, $errorOutput);
        if ($result->getExitCode() === StdioMessageCommand::EXIT_REFUSED) {
            throw new CallerRefusedException(__('A message process refused the caller.'));
        }

        $envelope = $result->getExitCode() === self::EXIT_SUCCESS
            ? $this->envelope->decode($result->getOutput())
            : null;
        if ($envelope === null) {
            $this->say($errorOutput, $this->describeFailure('An MCP message could not be handled', $result));
        }

        return $envelope;
    }

    /**
     * The argument list of a message process for this admin and session
     *
     * @param string $login
     * @param string $sessionId The session to use, or '' for none
     * @return list<string>
     */
    private function command(string $login, string $sessionId): array
    {
        $command = [
            $this->phpBinary,
            '-d',
            'display_errors=stderr',
            $this->directoryList->getRoot() . '/bin/magento',
            StdioMessageCommand::NAME,
            '--' . StdioMessageCommand::OPTION_ADMIN_USER . '=' . $login,
        ];
        if ($sessionId !== '') {
            $command[] = '--' . StdioMessageCommand::OPTION_SESSION . '=' . $sessionId;
        }

        return $command;
    }

    /**
     * Write an answer body to the output as one line, unless it is empty
     *
     * @param resource $output
     * @param string $body
     * @return void
     * @throws FileSystemException When the output cannot be written
     */
    private function writeLine(mixed $output, string $body): void
    {
        if ($body !== '') {
            $this->streams->write($output, $body . "\n");
        }
    }

    /**
     * Copy what a process wrote to its standard error
     *
     * @param ProcessResult $result
     * @param resource $errorOutput
     * @return void
     * @throws FileSystemException When the error output cannot be written
     */
    private function copyErrorOutput(ProcessResult $result, mixed $errorOutput): void
    {
        if ($result->getErrorOutput() !== '') {
            $this->streams->write($errorOutput, $result->getErrorOutput());
        }
    }

    /**
     * Write one diagnostic line
     *
     * @param resource $errorOutput
     * @param string $text
     * @return void
     * @throws FileSystemException When the error output cannot be written
     */
    private function say(mixed $errorOutput, string $text): void
    {
        $this->streams->write($errorOutput, $text . "\n");
    }

    /**
     * How a message process ended, for the error output
     *
     * @param string $what What failed
     * @param ProcessResult $result
     * @return string
     */
    private function describeFailure(string $what, ProcessResult $result): string
    {
        if ($result->isTimedOut()) {
            return sprintf('%s: the message process ran longer than %d seconds.', $what, $this->messageTimeoutSeconds);
        }

        if ($result->getExitCode() === null) {
            return sprintf('%s: the message process could not be run.', $what);
        }

        if ($result->getExitCode() !== self::EXIT_SUCCESS) {
            return sprintf('%s: the message process exited with code %d.', $what, $result->getExitCode());
        }

        if ($this->envelope->decode($result->getOutput()) !== null) {
            return sprintf('%s: the message process answered with an error; Magento\'s log holds the details.', $what);
        }

        return sprintf('%s: the message process wrote no valid answer envelope.', $what);
    }
}
