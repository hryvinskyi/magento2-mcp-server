<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi;

use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\Schema\InputSchemaBuilderInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Webapi\Authorization;
use Psr\Log\LoggerInterface;

/**
 * Ability source `webapi`: one ability per service method that a `webapi.xml` route calls
 *
 * - The routes come from the route index, one per service method, sorted by URL. Each becomes an ability through the
 *   route ability builder; a name already taken by an earlier route is skipped with a warning, so the first route in
 *   URL order keeps it.
 * - The abilities are listed once per request and kept until the request ends.
 * - The input schema is built from the Web API metadata of the route's method.
 * - The ACL check asks Magento's Web API authorization for every resource of the route, the rule REST applies. The
 *   resource `anonymous` is allowed only because Magento's Web API areas (REST and SOAP) register a plugin on that
 *   authorization which lets it through; outside those areas an `anonymous` ability is denied.
 * - A call runs the route's service method as REST would (WebapiInvoker).
 */
class WebapiAbilitySource implements AbilitySourceInterface, ResetAfterRequestInterface
{
    /**
     * Code of the source
     */
    public const CODE = 'webapi';

    /**
     * @var array<string, array{ability: AbilityInterface, route: Route}>|null
     */
    private ?array $entries = null;

    /**
     * @param RouteIndex $routeIndex
     * @param RouteAbilityBuilder $abilityBuilder
     * @param InputSchemaBuilderInterface $schemaBuilder
     * @param WebapiInvoker $invoker
     * @param Authorization $authorization
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RouteIndex $routeIndex,
        private readonly RouteAbilityBuilder $abilityBuilder,
        private readonly InputSchemaBuilderInterface $schemaBuilder,
        private readonly WebapiInvoker $invoker,
        private readonly Authorization $authorization,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return self::CODE;
    }

    /**
     * @inheritDoc
     */
    public function abilities(): iterable
    {
        foreach ($this->entries() as $entry) {
            yield $entry['ability'];
        }
    }

    /**
     * @inheritDoc
     */
    public function inputSchema(AbilityInterface $ability): array
    {
        return $this->schemaBuilder->build($ability, $this->routeOf($ability))->getSchema();
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(AbilityInterface $ability): bool
    {
        return $this->authorization->isAllowed($this->routeOf($ability)->getAclResources());
    }

    /**
     * @inheritDoc
     */
    public function invoke(AbilityInterface $ability, array $arguments): mixed
    {
        return $this->invoker->invoke($ability, $this->routeOf($ability), $arguments);
    }

    /**
     * Forget the abilities listed during the request that ended
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->entries = null;
    }

    /**
     * The route behind one of the source's abilities
     *
     * @param AbilityInterface $ability
     * @return Route
     * @throws NoSuchEntityException When the source does not offer the ability
     */
    private function routeOf(AbilityInterface $ability): Route
    {
        $entry = $this->entries()[$ability->getName()] ?? null;
        if ($entry === null || $ability->getSource() !== self::CODE) {
            throw new NoSuchEntityException(
                __('The Web API offers no ability named "%1".', $ability->getName())
            );
        }

        return $entry['route'];
    }

    /**
     * Ability and route by ability name, built on the first read of the request
     *
     * @return array<string, array{ability: AbilityInterface, route: Route}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach ($this->routeIndex->routes() as $route) {
            $ability = $this->abilityBuilder->build($route, self::CODE);
            if ($ability === null) {
                continue;
            }

            $taken = $entries[$ability->getName()]['route'] ?? null;
            if ($taken !== null) {
                $this->logger->warning(sprintf(
                    'MCP skips the Web API route %s: the route %s already offers the ability "%s".',
                    $route->getReference(),
                    $taken->getReference(),
                    $ability->getName()
                ));
                continue;
            }

            $entries[$ability->getName()] = ['ability' => $ability, 'route' => $route];
        }

        return $this->entries = $entries;
    }
}
