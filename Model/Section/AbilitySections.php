<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Section;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\Framework\Phrase;

/**
 * The sections an ability belongs to, read from the ACL tree
 *
 * A section is a node right below the root of the ACL tree (Catalog, Sales, Customers), named by that node's id.
 * The nodes in `split` are split one level deeper, so a broad area such as Stores becomes Stores › Settings,
 * Stores › Inventory and so on. Every resource below a section belongs to it, and an ability belongs to the sections
 * of the resources it needs: a module that adds products of a new type, with the catalog's resources, lands in
 * Catalog.
 *
 * A resource outside the tree takes the section `outside` names for it (the resources anyone or only the caller
 * itself may use); any other belongs to OTHER, and so does an ability that needs no resource.
 */
class AbilitySections
{
    public const OTHER = 'other';
    private const ROOT = 'Magento_Backend::admin';
    private const SEPARATOR = ' › ';

    /**
     * @var array<string, string>|null Section of each resource of the tree
     */
    private ?array $sections = null;

    /**
     * @var array<string, list<string>> Titles of each section of the tree, outermost first
     */
    private array $titles = [];

    /**
     * @param ProviderInterface $aclResources
     * @param string[] $split Ids of the sections whose children are sections of their own
     * @param array<string,string|Phrase> $outside Labels of the sections of resources outside the tree, by resource
     * @param string|Phrase $otherLabel
     */
    public function __construct(
        private readonly ProviderInterface $aclResources,
        private readonly array $split = [],
        private readonly array $outside = [],
        private readonly string|Phrase $otherLabel = 'Other'
    ) {
    }

    /**
     * Codes of the sections of the resources the ability needs, in the order of its resources
     *
     * @param AbilityInterface $ability
     * @return list<string>
     */
    public function of(AbilityInterface $ability): array
    {
        $sections = array_map(
            fn (string $resource): string => $this->ofResource($resource),
            $ability->getAclResources()
        );

        return $sections === [] ? [self::OTHER] : array_values(array_unique($sections));
    }

    /**
     * The label of a section
     *
     * @param string $section
     * @return string
     */
    public function labelOf(string $section): string
    {
        $this->sections();
        if (isset($this->titles[$section])) {
            return implode(self::SEPARATOR, array_map(
                static fn (string $title): string => (string) __($title),
                $this->titles[$section]
            ));
        }

        return (string) ($this->outside[$section] ?? $this->otherLabel);
    }

    /**
     * The section of one resource
     *
     * @param string $resource
     * @return string
     */
    private function ofResource(string $resource): string
    {
        return $this->sections()[$resource] ?? (isset($this->outside[$resource]) ? $resource : self::OTHER);
    }

    /**
     * Section of every resource of the tree
     *
     * @return array<string, string>
     */
    private function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $sections = [];
        foreach ($this->aclResources->getAclResources() as $node) {
            if (is_array($node) && ($node['id'] ?? null) === self::ROOT) {
                $sections[self::ROOT] = self::ROOT;
                $this->titles[self::ROOT] = [$this->titleOf($node)];
                foreach ($this->childrenOf($node) as $child) {
                    $this->place($child, [], $sections);
                }
            }
        }

        return $this->sections = $sections;
    }

    /**
     * Make a node a section, or split it, and put every resource below it in its section
     *
     * @param array<mixed> $node
     * @param list<string> $outerTitles Titles of the section the node was split from
     * @param array<string,string> $sections
     * @return void
     */
    private function place(array $node, array $outerTitles, array &$sections): void
    {
        $id = $this->idOf($node);
        if ($id === '') {
            return;
        }

        $titles = [...$outerTitles, $this->titleOf($node)];
        $sections[$id] = $id;
        $this->titles[$id] = $titles;

        $split = in_array($id, $this->split, true);
        foreach ($this->childrenOf($node) as $child) {
            if ($split) {
                $this->place($child, $titles, $sections);
                continue;
            }
            $this->assign($child, $id, $sections);
        }
    }

    /**
     * Put a node and every node below it in the section
     *
     * @param array<mixed> $node
     * @param string $section
     * @param array<string,string> $sections
     * @return void
     */
    private function assign(array $node, string $section, array &$sections): void
    {
        $id = $this->idOf($node);
        if ($id !== '') {
            $sections[$id] = $section;
        }
        foreach ($this->childrenOf($node) as $child) {
            $this->assign($child, $section, $sections);
        }
    }

    /**
     * The child nodes of a node
     *
     * @param array<mixed> $node
     * @return list<array<mixed>>
     */
    private function childrenOf(array $node): array
    {
        $children = $node['children'] ?? [];

        return is_array($children) ? array_values(array_filter($children, 'is_array')) : [];
    }

    /**
     * The id of a node, or '' when it has none
     *
     * @param array<mixed> $node
     * @return string
     */
    private function idOf(array $node): string
    {
        $id = $node['id'] ?? '';

        return is_string($id) ? $id : '';
    }

    /**
     * The title of a node, or its id when it has none
     *
     * @param array<mixed> $node
     * @return string
     */
    private function titleOf(array $node): string
    {
        $title = $node['title'] ?? '';

        return is_scalar($title) ? (string) $title : $this->idOf($node);
    }
}
