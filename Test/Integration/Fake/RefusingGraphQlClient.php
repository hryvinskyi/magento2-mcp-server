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

/**
 * Stands where the container would put the HTTP GraphQL client during integration tests: it sends nothing, records
 * every request it is asked to send, and fails it as an unreachable endpoint does
 *
 * One instance serves the whole test process, so a service built in an earlier test still reports to it.
 */
class RefusingGraphQlClient implements GraphQlClientInterface
{
    /**
     * The instance of this test process, once built
     *
     * @var RefusingGraphQlClient|null
     */
    private static ?RefusingGraphQlClient $instance = null;

    /**
     * Every request asked for, in order
     *
     * @var list<array{document: string, variables: array<string,mixed>}>
     */
    public array $requests = [];

    /**
     * The instance of this test process
     *
     * @return self
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @inheritDoc
     */
    public function request(string $document, array $variables): array
    {
        $this->requests[] = ['document' => $document, 'variables' => $variables];

        throw new GraphQlTransportException('Integration tests never send a GraphQL request over HTTP.');
    }
}
