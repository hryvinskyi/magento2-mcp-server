<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;

/**
 * A GraphQL client that records every request and gives the next queued answer, or throws the next queued failure
 */
class QueuedGraphQlClient implements GraphQlClientInterface
{
    /**
     * @var list<array{document: string, variables: array<string,mixed>}>
     */
    public array $requests = [];

    /**
     * @param list<array<string,mixed>|GraphQlTransportException> $answers
     */
    public function __construct(
        private array $answers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function request(string $document, array $variables): array
    {
        $this->requests[] = ['document' => $document, 'variables' => $variables];
        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new \LogicException('No answer is queued.');
        }
        if ($answer instanceof GraphQlTransportException) {
            throw $answer;
        }

        return $answer;
    }
}
