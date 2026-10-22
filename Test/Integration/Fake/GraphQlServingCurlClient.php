<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fake;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingCurlClient;

/**
 * An HTTP client that never touches the network: it records the headers of every POST and answers it with what a
 * GraphQL client answers to the JSON body's query and variables
 *
 * Under the real HTTP GraphQL client it shows what that client sends, headers included.
 */
class GraphQlServingCurlClient extends RecordingCurlClient
{
    /**
     * Every POST: the headers set for it and the query it carried
     *
     * @var list<array{headers: array<string,string>, query: string}>
     */
    public array $posts = [];

    /**
     * @var string
     */
    private string $answer = '';

    /**
     * @param GraphQlClientInterface $graphQl Answers each query
     */
    public function __construct(
        private readonly GraphQlClientInterface $graphQl
    ) {
        parent::__construct();
    }

    /**
     * @param string $uri
     * @param array<mixed>|string $params
     * @return void
     */
    public function post($uri, $params)
    {
        parent::post($uri, $params);

        $body = json_decode(is_string($params) ? $params : '', true, 512, JSON_THROW_ON_ERROR);
        $query = is_array($body) && is_string($body['query'] ?? null) ? $body['query'] : '';
        $variables = [];
        foreach (is_array($body) && is_array($body['variables'] ?? null) ? $body['variables'] : [] as $name => $value) {
            $variables[(string) $name] = $value;
        }

        $this->posts[] = ['headers' => $this->headers, 'query' => $query];
        $this->answer = json_encode($this->graphQl->request($query, $variables), JSON_THROW_ON_ERROR);
    }

    /**
     * @inheritDoc
     */
    public function getBody()
    {
        return $this->answer;
    }
}
