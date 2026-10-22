<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Schema;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlClientInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\GraphQlTransportException;
use Psr\Log\LoggerInterface;

/**
 * Reads the schema by sending the standard introspection query to the store's GraphQL endpoint
 *
 * - The query asks for the root type names and every type with its fields, arguments, input fields, interfaces, enum
 *   values and possible types, deprecated ones included, type references resolved through seven `ofType` levels.
 * - The operation is named `IntrospectionQuery`: Magento's GraphQL controller keeps such a query out of its query log.
 * - Every failure gives null and a logged warning: the endpoint gave no answer, the answer holds errors (as it does
 *   when introspection is disabled by the `graphql/disable_introspection` key of `app/etc/env.php`), or it holds no
 *   readable schema. Nothing is remembered here; CachedSchemaProvider remembers failures.
 */
class IntrospectionSchemaProvider implements SchemaProviderInterface
{
    /**
     * The introspection query
     */
    public const QUERY = <<<'GRAPHQL'
query IntrospectionQuery {
  __schema {
    queryType { name }
    mutationType { name }
    types { ...FullType }
  }
}

fragment FullType on __Type {
  kind
  name
  description
  fields(includeDeprecated: true) {
    name
    description
    args { ...InputValue }
    type { ...TypeRef }
    isDeprecated
    deprecationReason
  }
  inputFields { ...InputValue }
  interfaces { ...TypeRef }
  enumValues(includeDeprecated: true) {
    name
    description
    isDeprecated
    deprecationReason
  }
  possibleTypes { ...TypeRef }
}

fragment InputValue on __InputValue {
  name
  description
  type { ...TypeRef }
  defaultValue
}

fragment TypeRef on __Type {
  kind
  name
  ofType {
    kind
    name
    ofType {
      kind
      name
      ofType {
        kind
        name
        ofType {
          kind
          name
          ofType {
            kind
            name
            ofType {
              kind
              name
              ofType {
                kind
                name
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

    /**
     * The most error messages quoted in a warning
     */
    private const LOGGED_ERRORS = 5;

    /**
     * @param GraphQlClientInterface $client
     * @param SchemaReader $reader
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GraphQlClientInterface $client,
        private readonly SchemaReader $reader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function schema(): ?IntrospectedSchema
    {
        try {
            $answer = $this->client->request(self::QUERY, []);
        } catch (GraphQlTransportException $exception) {
            $this->warn('the endpoint did not answer: ' . $exception->getMessage());

            return null;
        }

        $errors = $answer['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $this->warn('the endpoint answered with errors: ' . $this->messagesOf($errors));

            return null;
        }

        $data = $answer['data'] ?? null;
        $schemaNode = is_array($data) ? ($data['__schema'] ?? null) : null;
        $schema = is_array($schemaNode) ? $this->reader->read($schemaNode) : null;
        if ($schema === null) {
            $this->warn('the answer holds no readable schema.');
        }

        return $schema;
    }

    /**
     * Log why no schema could be read
     *
     * @param string $reason
     * @return void
     */
    private function warn(string $reason): void
    {
        $this->logger->warning('MCP offers no GraphQL abilities: the GraphQL schema could not be read; ' . $reason);
    }

    /**
     * The first few error messages of a GraphQL answer, joined
     *
     * @param array<mixed> $errors
     * @return string
     */
    private function messagesOf(array $errors): string
    {
        $messages = [];
        foreach (array_slice($errors, 0, self::LOGGED_ERRORS) as $error) {
            $message = is_array($error) ? ($error['message'] ?? null) : null;
            $messages[] = is_string($message) ? $message : '(no message)';
        }

        return implode(' | ', $messages);
    }
}
