<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Call;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Operation;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\IntrospectedSchema;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Schema\NodeReader;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Calls one GraphQL root field with a client's arguments: builds the operation, sends it, maps the answer
 *
 * Arguments that do not suit the field are refused before anything is sent. When the endpoint gives no GraphQL
 * answer, the client is told only that it did not answer; the details go to the log.
 *
 * @phpstan-import-type Field from NodeReader
 */
class FieldCaller
{
    /**
     * @param OperationBuilder $operationBuilder
     * @param GraphQlClientInterface $client
     * @param AnswerMapper $answerMapper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OperationBuilder $operationBuilder,
        private readonly GraphQlClientInterface $client,
        private readonly AnswerMapper $answerMapper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * What the field answers to these arguments
     *
     * @param IntrospectedSchema $schema
     * @param Operation $operation
     * @param Field $field
     * @param string $abilityName
     * @param array<string,mixed> $arguments
     * @return mixed
     * @throws LocalizedException When the arguments do not suit the field, the endpoint does not answer, or the
     *     answer holds errors and no value
     */
    public function call(
        IntrospectedSchema $schema,
        Operation $operation,
        array $field,
        string $abilityName,
        array $arguments
    ): mixed {
        $call = $this->operationBuilder->build($schema, $operation, $field, $arguments);

        try {
            $answer = $this->client->request($call['document'], $call['variables']);
        } catch (GraphQlTransportException $exception) {
            $this->logger->warning(
                sprintf('MCP GraphQL ability "%s" got no answer: %s', $abilityName, $exception->getMessage()),
                ['exception' => $exception]
            );
            throw new LocalizedException(__('The store\'s GraphQL endpoint did not answer.'), $exception);
        }

        return $this->answerMapper->map($abilityName, $field['name'], $answer);
    }
}
