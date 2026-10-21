<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\Source\Webapi\Metadata\MetadataReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * The ability a Web API route offers
 *
 * - The name comes from the route's service interface and method (AbilityNamer).
 * - The title is the first sentence of the method's documentation in the Web API metadata, else the method name in
 *   words (`getList` is "Get list"); the description is the whole documentation.
 * - The category is the Magento module of the service interface (`Magento_Catalog`).
 * - The access follows the route's HTTP method: GET reads, POST and PUT write, DELETE deletes. The di.xml argument
 *   `accessOverrides` (ability name to `read`, `write` or `delete`) replaces it for a route whose HTTP method tells
 *   the wrong story, such as a GET route that changes data. An override with any other value drops the ability,
 *   logged, rather than guess its access.
 * - The ACL resources are the route's.
 *
 * A route that cannot be named, uses another HTTP method, or gives values the ability refuses is logged as a warning
 * and gives no ability; it never stops the other routes from becoming abilities.
 */
class RouteAbilityBuilder
{
    /**
     * @param AbilityNamer $namer
     * @param MetadataReaderInterface $metadata
     * @param AbilityInterfaceFactory $abilityFactory
     * @param LoggerInterface $logger
     * @param array<string,string> $accessOverrides Access (`read`, `write`, `delete`) by ability name
     */
    public function __construct(
        private readonly AbilityNamer $namer,
        private readonly MetadataReaderInterface $metadata,
        private readonly AbilityInterfaceFactory $abilityFactory,
        private readonly LoggerInterface $logger,
        private readonly array $accessOverrides = []
    ) {
    }

    /**
     * The ability the route offers, or null (logged) for a route that cannot be one
     *
     * @param Route $route
     * @param string $source Code of the ability source the ability belongs to
     * @return AbilityInterface|null
     */
    public function build(Route $route, string $source): ?AbilityInterface
    {
        $class = $route->getServiceClass();
        $method = $route->getServiceMethod();

        $name = $this->namer->nameOf($class, $method);
        if ($name === null) {
            $this->logSkip($route, sprintf('the service %s has no Api or Service namespace to name it by', $class));

            return null;
        }

        $access = $this->accessOf($route->getHttpMethod());
        if ($access === null) {
            $this->logSkip($route, sprintf('the HTTP method %s says nothing of its access', $route->getHttpMethod()));

            return null;
        }

        $override = $this->accessOverrides[$name] ?? null;
        if ($override !== null) {
            $access = AbilityAccess::tryFrom($override);
        }
        if ($access === null) {
            $this->logSkip($route, sprintf(
                'the access override "%s" of the ability "%s" is not read, write or delete',
                (string) $override,
                $name
            ));

            return null;
        }

        $documentation = $this->metadata->methodOf($class, $method, $route->getVersion())['documentation'] ?? '';

        try {
            return $this->abilityFactory->create([
                'name' => $name,
                'title' => $this->titleOf($documentation, $method),
                'category' => $this->namer->categoryOf($class) ?? '',
                'source' => $source,
                'sourceReference' => $route->getReference(),
                'access' => $access,
                'aclResources' => $route->getAclResources(),
                'description' => $documentation,
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->logSkip($route, $exception->getMessage());

            return null;
        }
    }

    /**
     * The access an HTTP method stands for, or null for a method Magento's Web API does not route
     *
     * @param string $httpMethod
     * @return AbilityAccess|null
     */
    private function accessOf(string $httpMethod): ?AbilityAccess
    {
        return match (strtoupper($httpMethod)) {
            'GET' => AbilityAccess::Read,
            'POST', 'PUT' => AbilityAccess::Write,
            'DELETE' => AbilityAccess::Delete,
            default => null,
        };
    }

    /**
     * The first sentence of the documentation, else the method name in words
     *
     * @param string $documentation
     * @param string $method
     * @return string
     */
    private function titleOf(string $documentation, string $method): string
    {
        $firstLine = trim(strtok($documentation, "\r\n") ?: '');
        $sentence = preg_split('/(?<=\.)\s/', $firstLine, 2)[0] ?? '';
        $title = rtrim(trim($sentence), '.');

        return $title !== '' ? $title : ucfirst(str_replace('_', ' ', $this->namer->snake($method)));
    }

    /**
     * Log why a route gives no ability
     *
     * @param Route $route
     * @param string $reason
     * @return void
     */
    private function logSkip(Route $route, string $reason): void
    {
        $this->logger->warning(sprintf('MCP skips the Web API route %s: %s.', $route->getReference(), $reason));
    }
}
