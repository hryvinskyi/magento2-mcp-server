<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\Section\AbilitySections;

/**
 * The sections of the catalog as the MCP Server page lists them: each with its abilities, how many of them clients
 * may call, the modules they come from and whether the section is switched off
 *
 * A section switched off whose abilities are all gone (their module was removed) is still listed, so it can be
 * switched on again.
 *
 * @phpstan-import-type AbilityRow from AbilityRows
 * @phpstan-type SectionRow array{
 *     code: string,
 *     label: string,
 *     abilities: int,
 *     exposed: int,
 *     modules: list<string>,
 *     off: bool
 * }
 */
class CatalogSections
{
    /**
     * @param AbilityRows $rows
     * @param AbilitySections $sections
     * @param SectionSwitchesInterface $switches
     */
    public function __construct(
        private readonly AbilityRows $rows,
        private readonly AbilitySections $sections,
        private readonly SectionSwitchesInterface $switches
    ) {
    }

    /**
     * Every section, by label
     *
     * @return list<SectionRow>
     */
    public function all(): array
    {
        return $this->fromRows($this->rows->all());
    }

    /**
     * Every section, by label, counted again from the catalog
     *
     * For a caller that switched a section earlier in the same request: rows read before the switch would not show
     * its counts.
     *
     * @return list<SectionRow>
     */
    public function recounted(): array
    {
        return $this->recountedWithTotal()['sections'];
    }

    /**
     * Every section and the page's total of abilities a client may call, both counted from one fresh read
     *
     * The rows are read again once, and the sections and the total come from that same read, so the total can never
     * be the one from before a switch made earlier in the request.
     *
     * @return array{sections: list<SectionRow>, exposed: int}
     */
    public function recountedWithTotal(): array
    {
        $rows = $this->rows->recount();

        return ['sections' => $this->fromRows($rows), 'exposed' => $this->rows->exposedCount()];
    }

    /**
     * The sections of these ability rows, by label, with every section switched off listed too
     *
     * @param list<AbilityRow> $rows
     * @return list<SectionRow>
     */
    private function fromRows(array $rows): array
    {
        $sections = [];
        foreach ($this->switches->off() as $code) {
            $sections[$code] = $this->blank($code);
        }
        foreach ($rows as $row) {
            foreach ($row['sections'] as $code) {
                $section = $sections[$code] ?? $this->blank($code);
                $section['abilities']++;
                $section['exposed'] += Exposure::from($row['exposure'])->allowsCall() ? 1 : 0;
                $section['modules'][] = $row['category'];
                $sections[$code] = $section;
            }
        }

        $listed = [];
        foreach ($sections as $section) {
            $modules = array_values(array_unique($section['modules']));
            sort($modules, SORT_STRING | SORT_FLAG_CASE);
            $section['modules'] = $modules;
            $listed[] = $section;
        }
        usort($listed, static fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']));

        return $listed;
    }

    /**
     * The listed section of that code, or null when no section has it
     *
     * @param string $code
     * @return SectionRow|null
     */
    public function find(string $code): ?array
    {
        foreach ($this->all() as $section) {
            if ($section['code'] === $code) {
                return $section;
            }
        }

        return null;
    }

    /**
     * A section with no abilities counted yet
     *
     * @param string $code
     * @return SectionRow
     */
    private function blank(string $code): array
    {
        return [
            'code' => $code,
            'label' => $this->sections->labelOf($code),
            'abilities' => 0,
            'exposed' => 0,
            'modules' => [],
            'off' => $this->switches->isOff($code),
        ];
    }
}
