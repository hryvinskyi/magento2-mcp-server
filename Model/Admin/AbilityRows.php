<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;
use Hryvinskyi\McpServer\Model\Section\AbilitySections;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Every ability of the catalog as a row of the admin's catalog grid: what it is, and its exposure with the reason
 *
 * A row also tells whether the merchant put the ability on the deny-list or asked for confirmation here, apart from
 * what the module's own rules decide, the sections it belongs to (`section` is the first of them) and whether the
 * personal data in its results is `hidden` or `shown`. The rows are read once per request.
 *
 * @phpstan-type AbilityRow array{
 *     name: string,
 *     title: string,
 *     source: string,
 *     category: string,
 *     access: string,
 *     aclResources: list<string>,
 *     exposure: string,
 *     reason: string,
 *     deniedHere: bool,
 *     confirmHere: bool,
 *     sections: list<string>,
 *     section: string,
 *     personalData: string
 * }
 */
class AbilityRows implements ResetAfterRequestInterface
{
    public const PERSONAL_DATA_HIDDEN = 'hidden';
    public const PERSONAL_DATA_SHOWN = 'shown';

    /**
     * More abilities than any store offers, so one page holds the whole catalog
     */
    private const WHOLE_CATALOG = 100000;

    /**
     * @var list<AbilityRow>|null
     */
    private ?array $rows = null;

    /**
     * @param AbilityCatalogInterface $catalog
     * @param ExposurePolicyInterface $exposurePolicy
     * @param AbilityOverrideRepositoryInterface $overrides
     * @param AbilitySections $sections
     * @param PersonalDataPolicyInterface $personalData
     */
    public function __construct(
        private readonly AbilityCatalogInterface $catalog,
        private readonly ExposurePolicyInterface $exposurePolicy,
        private readonly AbilityOverrideRepositoryInterface $overrides,
        private readonly AbilitySections $sections,
        private readonly PersonalDataPolicyInterface $personalData
    ) {
    }

    /**
     * The rows of every ability, sorted by name
     *
     * @return list<AbilityRow>
     */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $overrides = $this->overrides->all();
        $rows = [];
        foreach ($this->catalog->search('', null, null, self::WHOLE_CATALOG, 0)->getItems() as $ability) {
            $decision = $this->exposurePolicy->decisionOf($ability);
            $override = $overrides[$ability->getName()] ?? null;
            $sections = $this->sections->of($ability);
            $rows[] = [
                'name' => $ability->getName(),
                'title' => $ability->getTitle(),
                'source' => $ability->getSource(),
                'category' => $ability->getCategory(),
                'access' => $ability->getAccess()->value,
                'aclResources' => $ability->getAclResources(),
                'exposure' => $decision->getExposure()->value,
                'reason' => $decision->getDenialReason(),
                'deniedHere' => $override !== null && $override->isDenied(),
                'confirmHere' => $override !== null && $override->needsConfirmation(),
                'sections' => $sections,
                'section' => $sections[0] ?? AbilitySections::OTHER,
                'personalData' => $this->personalData->hidesFor($ability->getName())
                    ? self::PERSONAL_DATA_HIDDEN
                    : self::PERSONAL_DATA_SHOWN,
            ];
        }

        return $this->rows = $rows;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->rows = null;
    }
}
