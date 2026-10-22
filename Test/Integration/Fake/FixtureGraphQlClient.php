<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * A GraphQL client that never touches the network: it records every request, answers introspection with the stored
 * introspection of a real store (`_files/graphql-introspection.json`), and any other operation with the next answer
 * the test queued
 *
 * Switched to failing, it throws a transport failure for every request, as an unreachable endpoint does.
 */
class FixtureGraphQlClient implements GraphQlClientInterface
{
    /**
     * Every request, in order
     *
     * @var list<array{document: string, variables: array<string,mixed>}>
     */
    public array $requests = [];

    /**
     * Answers to operations other than introspection, in the order they are asked for
     *
     * @var list<array<string,mixed>>
     */
    public array $answers = [];

    /**
     * @var array<string,mixed>|null
     */
    private ?array $introspection = null;

    /**
     * @param bool $failing Whether every request fails as an unreachable endpoint does
     * @param array<string,list<array<string,mixed>>> $addedRootFields Fields added to the stored schema's root
     *     types, keyed by operation (`query`, `mutation`), for a store whose schema has more fields
     */
    public function __construct(
        public bool $failing = false,
        private readonly array $addedRootFields = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function request(string $document, array $variables): array
    {
        $this->requests[] = ['document' => $document, 'variables' => $variables];
        if ($this->failing) {
            throw new GraphQlTransportException('Connection refused');
        }
        if (str_contains($document, '__schema')) {
            return $this->introspection();
        }

        $answer = array_shift($this->answers);
        if ($answer === null) {
            throw new \LogicException('No answer is queued for this operation.');
        }

        return $answer;
    }

    /**
     * The stored introspection answer
     *
     * @return array<string,mixed>
     */
    private function introspection(): array
    {
        if ($this->introspection === null) {
            $decoded = json_decode(
                (new File())->fileGetContents(__DIR__ . '/../_files/graphql-introspection.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($decoded)) {
                throw new \LogicException('The stored introspection is not a JSON object.');
            }
            $answer = [];
            foreach ($decoded as $key => $value) {
                $answer[(string) $key] = $value;
            }
            $this->introspection = $this->withAddedRootFields($answer);
        }

        return $this->introspection;
    }

    /**
     * The introspection answer with the added fields appended to the root types they are added to
     *
     * @param array<string,mixed> $answer
     * @return array<string,mixed>
     */
    private function withAddedRootFields(array $answer): array
    {
        $schema = is_array($answer['data'] ?? null) ? $answer['data']['__schema'] ?? null : null;
        if (!is_array($schema) || !is_array($schema['types'] ?? null)) {
            return $answer;
        }

        $rootTypeNames = [
            'query' => is_array($schema['queryType'] ?? null) ? $schema['queryType']['name'] ?? null : null,
            'mutation' => is_array($schema['mutationType'] ?? null) ? $schema['mutationType']['name'] ?? null : null,
        ];
        $types = [];
        foreach ($schema['types'] as $type) {
            if (is_array($type) && is_array($type['fields'] ?? null)) {
                foreach ($rootTypeNames as $operation => $rootTypeName) {
                    if (($type['name'] ?? null) === $rootTypeName) {
                        $type['fields'] = [...$type['fields'], ...$this->addedRootFields[$operation] ?? []];
                    }
                }
            }
            $types[] = $type;
        }
        $schema['types'] = $types;
        $answer['data'] = ['__schema' => $schema];

        return $answer;
    }
}
