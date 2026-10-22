<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Pool;

use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\PoolAbilityInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Webapi\Authorization;
use Psr\Log\LoggerInterface;

/**
 * Ability source `pool`: the abilities modules register in the di.xml array `abilities`, keyed by ability name
 *
 * - Each item becomes an ability of this source with the source reference `pool:<key>`. The ability is built from the
 *   item's own values, never from anything a client sent.
 * - An item is skipped with a warning, and every other item still listed, when it is not a PoolAbilityInterface, when
 *   its key is not its name, when it names no ACL resource or a resource that is not a non-empty string, when a value
 *   breaks the rules of an ability (the name format, a blank title or category) and the ability or the item refuses
 *   it, or when reading the item fails in any other way.
 * - An ability without ACL resources would be allowed for every caller, since Magento's Web API authorization allows
 *   an empty list; that is why such an item is skipped rather than listed.
 * - The abilities are listed once per request and kept until the request ends.
 * - The input schema is the item's, with every empty JSON object given as `{}`.
 * - The ACL check asks Magento's Web API authorization for every resource of the ability, the rule REST applies.
 * - A call runs the item's execute() with the client's arguments.
 */
class PoolAbilitySource implements AbilitySourceInterface, ResetAfterRequestInterface
{
    /**
     * Code of the source
     */
    public const CODE = 'pool';

    /**
     * @var array<string, array{ability: AbilityInterface, item: PoolAbilityInterface}>|null
     */
    private ?array $entries = null;

    /**
     * @param AbilityInterfaceFactory $abilityFactory
     * @param InputSchemaNormaliser $schemaNormaliser
     * @param Authorization $authorization
     * @param LoggerInterface $logger
     * @param array<mixed> $abilities PoolAbilityInterface items keyed by ability name
     */
    public function __construct(
        private readonly AbilityInterfaceFactory $abilityFactory,
        private readonly InputSchemaNormaliser $schemaNormaliser,
        private readonly Authorization $authorization,
        private readonly LoggerInterface $logger,
        private readonly array $abilities = []
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
        return $this->schemaNormaliser->normalise($this->entryOf($ability)['item']->getInputSchema());
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(AbilityInterface $ability): bool
    {
        return $this->authorization->isAllowed($this->entryOf($ability)['ability']->getAclResources());
    }

    /**
     * @inheritDoc
     */
    public function invoke(AbilityInterface $ability, array $arguments): mixed
    {
        return $this->entryOf($ability)['item']->execute($arguments);
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
     * The listed ability and its item behind one of the source's abilities
     *
     * @param AbilityInterface $ability
     * @return array{ability: AbilityInterface, item: PoolAbilityInterface}
     * @throws NoSuchEntityException When the source does not offer the ability
     */
    private function entryOf(AbilityInterface $ability): array
    {
        $entry = $this->entries()[$ability->getName()] ?? null;
        if ($entry === null || $ability->getSource() !== self::CODE) {
            throw new NoSuchEntityException(
                __('No registered ability is named "%1".', $ability->getName())
            );
        }

        return $entry;
    }

    /**
     * Ability and item by ability name, built on the first read of the request
     *
     * @return array<string, array{ability: AbilityInterface, item: PoolAbilityInterface}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach ($this->abilities as $key => $item) {
            if (!$item instanceof PoolAbilityInterface) {
                $this->logSkip((string) $key, sprintf(
                    'it is a %s, not a %s',
                    get_debug_type($item),
                    PoolAbilityInterface::class
                ));
                continue;
            }

            $ability = $this->abilityOf((string) $key, $item);
            if ($ability !== null) {
                $entries[$ability->getName()] = ['ability' => $ability, 'item' => $item];
            }
        }

        return $this->entries = $entries;
    }

    /**
     * The ability an item offers, or null (logged) for an item that cannot be one
     *
     * @param string $key
     * @param PoolAbilityInterface $item
     * @return AbilityInterface|null
     */
    private function abilityOf(string $key, PoolAbilityInterface $item): ?AbilityInterface
    {
        try {
            $name = $item->getName();
            if ($name !== $key) {
                $this->logSkip($key, sprintf('its key is not its name "%s"', $name));

                return null;
            }

            $aclResources = $this->aclResourcesOf($item->getAclResources());
            if ($aclResources === []) {
                $this->logSkip($key, 'it names no ACL resource, so every caller would be allowed to call it');

                return null;
            }
            if ($aclResources === null) {
                $this->logSkip($key, 'an ACL resource it names is not a non-empty string');

                return null;
            }

            return $this->abilityFactory->create([
                'name' => $name,
                'title' => $item->getTitle(),
                'category' => $item->getCategory(),
                'source' => self::CODE,
                'sourceReference' => self::CODE . ':' . $key,
                'access' => $item->getAccess(),
                'aclResources' => $aclResources,
                'description' => $item->getDescription(),
            ]);
        } catch (\Throwable $exception) {
            $this->logSkip($key, $exception->getMessage());

            return null;
        }
    }

    /**
     * The ACL resources an item names, as a list, or null when one of them is not a non-empty string
     *
     * The published contract promises strings, but an item is another module's code: a value that breaks the promise
     * must skip the item, not reach the ability, whose typed constructor would fail for every item of the source.
     *
     * @param array<mixed> $resources
     * @return list<string>|null
     */
    private function aclResourcesOf(array $resources): ?array
    {
        $checked = [];
        foreach ($resources as $resource) {
            if (!is_string($resource) || trim($resource) === '') {
                return null;
            }
            $checked[] = $resource;
        }

        return $checked;
    }

    /**
     * Log why an item gives no ability
     *
     * @param string $key
     * @param string $reason
     * @return void
     */
    private function logSkip(string $key, string $reason): void
    {
        $this->logger->warning(sprintf('MCP skips the registered ability "%s": %s.', $key, rtrim($reason, '.')));
    }
}
