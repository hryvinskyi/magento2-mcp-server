<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Call;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Turns the GraphQL answer to a root field's call into what the ability returns
 *
 * - No errors: the value of `data.<field>`.
 * - Errors and no value (`data.<field>` null or missing), the usual shape of a failed resolver
 *   (`{"errors": […], "data": {"products": null}}`): a failure whose message holds the error messages, so the model
 *   can correct its call.
 * - Errors and a value: partial data, answered as `{"data": <value>, "errors": [<messages>]}`.
 *
 * Only the `message` strings of the errors ever reach the client. Everything else about them, `extensions` above all
 * (debug messages and traces in developer mode), goes only to the log, shortened.
 */
class AnswerMapper
{
    /**
     * The most error messages passed on
     */
    private const MAX_MESSAGES = 10;

    /**
     * How much of the errors is logged, in bytes
     */
    private const LOGGED_ERRORS_BYTES = 4000;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * What the ability returns for this answer
     *
     * @param string $abilityName
     * @param string $fieldName
     * @param array<string,mixed> $answer The decoded answer, with `data`, `errors` or both
     * @return mixed
     * @throws LocalizedException When the answer holds errors and no value
     */
    public function map(string $abilityName, string $fieldName, array $answer): mixed
    {
        $data = $answer['data'] ?? null;
        $value = is_array($data) ? ($data[$fieldName] ?? null) : null;

        $errors = $answer['errors'] ?? [];
        if ($errors === []) {
            return $value;
        }
        $errors = is_array($errors) ? $errors : [$errors];
        $this->log($abilityName, $errors);

        $messages = $this->messagesOf($errors);
        if ($value === null) {
            throw new LocalizedException(__('The GraphQL query failed: %1', implode(' | ', $messages)));
        }

        return ['data' => $value, 'errors' => $messages];
    }

    /**
     * The message of each error, '(no message)' for one without
     *
     * @param array<mixed> $errors
     * @return list<string>
     */
    private function messagesOf(array $errors): array
    {
        $messages = [];
        foreach (array_slice($errors, 0, self::MAX_MESSAGES) as $error) {
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            $messages[] = is_string($message) && $message !== '' ? $message : '(no message)';
        }

        return $messages;
    }

    /**
     * Log the errors in full, extensions included, shortened
     *
     * @param string $abilityName
     * @param array<mixed> $errors
     * @return void
     */
    private function log(string $abilityName, array $errors): void
    {
        $encoded = json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->logger->info(
            sprintf('MCP GraphQL ability "%s" got errors from the GraphQL endpoint.', $abilityName),
            ['errors' => substr($encoded === false ? '' : $encoded, 0, self::LOGGED_ERRORS_BYTES)]
        );
    }
}
