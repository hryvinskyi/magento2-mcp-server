<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterfaceFactory;
use Hryvinskyi\McpServer\Model\Catalog\AbilitySourceResolverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The abilities of every source in the di.xml pool `sources`, keyed by source code
 *
 * The first read of a request asks every source for its abilities and keeps one index, name → ability and the source
 * that offers it, sorted by name; every later read of the request is answered from it, and the index is dropped
 * between requests.
 *
 * An ability name is unique across sources: when two sources offer the same name, the first source in the pool keeps
 * it and the other ability is skipped with a warning. An ability that names a source other than the one that listed
 * it is skipped with a warning too, since no source would answer for it.
 *
 * The schema and the ACL of an ability are asked of the source that offers it.
 */
class AbilityCatalog implements AbilityCatalogInterface, AbilitySourceResolverInterface, ResetAfterRequestInterface
{
    /**
     * @var array<string, array{ability: AbilityInterface, source: AbilitySourceInterface}>|null
     */
    private ?array $index = null;

    /**
     * @param AbilityPageInterfaceFactory $pageFactory
     * @param LoggerInterface $logger
     * @param AbilitySourceInterface[] $sources
     */
    public function __construct(
        private readonly AbilityPageInterfaceFactory $pageFactory,
        private readonly LoggerInterface $logger,
        private readonly array $sources = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $name): AbilityInterface
    {
        $entry = $this->index()[$name] ?? null;
        if ($entry === null) {
            throw new NoSuchEntityException(__('There is no ability named "%1".', $name));
        }

        return $entry['ability'];
    }

    /**
     * @inheritDoc
     *
     * Every word of the query must occur in the name, the title or the category; the category filter ignores case.
     */
    public function search(
        string $query,
        ?string $category,
        ?AbilityAccess $access,
        int $limit,
        int $offset
    ): AbilityPageInterface {
        if ($limit < 1) {
            throw new \InvalidArgumentException(sprintf('A page holds at least one ability, not %d.', $limit));
        }
        if ($offset < 0) {
            throw new \InvalidArgumentException(sprintf('A search skips zero or more abilities, not %d.', $offset));
        }

        $words = $this->wordsOf($query);
        $matches = [];
        foreach ($this->index() as $entry) {
            $ability = $entry['ability'];
            if ($this->matches($ability, $words, $category, $access)) {
                $matches[] = $ability;
            }
        }

        return $this->pageFactory->create([
            'total' => count($matches),
            'items' => array_slice($matches, $offset, $limit),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function schemaOf(AbilityInterface $ability): array
    {
        return $this->sourceOf($ability)->inputSchema($ability);
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(AbilityInterface $ability): bool
    {
        return $this->sourceOf($ability)->isAllowed($ability);
    }

    /**
     * @inheritDoc
     */
    public function sourceOf(AbilityInterface $ability): AbilitySourceInterface
    {
        $entry = $this->index()[$ability->getName()] ?? null;
        if ($entry === null || $entry['ability']->getSource() !== $ability->getSource()) {
            throw new NoSuchEntityException(
                __('No source offers the ability "%1" of source "%2".', $ability->getName(), $ability->getSource())
            );
        }

        return $entry['source'];
    }

    /**
     * Forget the abilities read during the request that ended
     *
     * @return void
     */
    public function _resetState(): void
    {
        $this->index = null;
    }

    /**
     * The index of the request, built on the first read
     *
     * @return array<string, array{ability: AbilityInterface, source: AbilitySourceInterface}>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        foreach ($this->sources as $source) {
            foreach ($source->abilities() as $ability) {
                if ($this->isAdmissible($ability, $source, $index)) {
                    $index[$ability->getName()] = ['ability' => $ability, 'source' => $source];
                }
            }
        }
        ksort($index, SORT_STRING);

        return $this->index = $index;
    }

    /**
     * Whether the ability may join the index: it names its own source and its name is still free
     *
     * @param AbilityInterface $ability
     * @param AbilitySourceInterface $source
     * @param array<string,array{ability:AbilityInterface,source:AbilitySourceInterface}> $index
     * @return bool
     */
    private function isAdmissible(AbilityInterface $ability, AbilitySourceInterface $source, array $index): bool
    {
        if ($ability->getSource() !== $source->getCode()) {
            $this->logger->warning(sprintf(
                'MCP ability "%s" is skipped: source "%s" listed it, but the ability names source "%s".',
                $ability->getName(),
                $source->getCode(),
                $ability->getSource()
            ));

            return false;
        }

        $owner = $index[$ability->getName()]['source'] ?? null;
        if ($owner !== null) {
            $this->logger->warning(sprintf(
                'MCP ability "%s" of source "%s" is skipped: source "%s" already offers an ability of that name.',
                $ability->getName(),
                $source->getCode(),
                $owner->getCode()
            ));

            return false;
        }

        return true;
    }

    /**
     * Whether the ability passes every filter of the search
     *
     * @param AbilityInterface $ability
     * @param list<string> $words Lowercase words, all of which must occur
     * @param string|null $category
     * @param AbilityAccess|null $access
     * @return bool
     */
    private function matches(AbilityInterface $ability, array $words, ?string $category, ?AbilityAccess $access): bool
    {
        if ($category !== null && strcasecmp($ability->getCategory(), $category) !== 0) {
            return false;
        }
        if ($access !== null && $ability->getAccess() !== $access) {
            return false;
        }

        $haystack = mb_strtolower(
            implode("\n", [$ability->getName(), $ability->getTitle(), $ability->getCategory()])
        );
        foreach ($words as $word) {
            if (!str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lowercase words of a query; none for a blank one
     *
     * A query that is not valid UTF-8 cannot be split into words, so it is looked for as a whole.
     *
     * @param string $query
     * @return list<string>
     */
    private function wordsOf(string $query): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [$query] : $words;
    }
}
