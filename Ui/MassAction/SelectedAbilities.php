<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\MassAction;

use Hryvinskyi\McpServer\Ui\DataProvider\MatchingAbilitiesInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

/**
 * The abilities a mass action of the catalog grid was sent for
 *
 * The grid sends either the names picked one by one ("selected"), or "select all" ("excluded": "false", or the names
 * left out): then every ability the grid's search and filters select, as the request carries them, minus those left
 * out.
 */
class SelectedAbilities
{
    /**
     * @param Filter $filter
     */
    public function __construct(
        private readonly Filter $filter
    ) {
    }

    /**
     * The names of the selected abilities
     *
     * @param RequestInterface $request
     * @return list<string>
     * @throws LocalizedException When the request names no listing
     */
    public function of(RequestInterface $request): array
    {
        $selected = $this->names($request->getParam(Filter::SELECTED_PARAM));
        if ($selected !== []) {
            return $selected;
        }

        $excluded = $request->getParam(Filter::EXCLUDED_PARAM);
        if ($excluded !== 'false' && !is_array($excluded)) {
            return [];
        }

        $component = $this->filter->getComponent();
        $this->filter->prepareComponent($component);
        $provider = $component->getContext()->getDataProvider();
        if (!$provider instanceof MatchingAbilitiesInterface) {
            return [];
        }

        return array_values(array_diff($provider->matchingNames(), $this->names($excluded)));
    }

    /**
     * The names a request parameter lists
     *
     * @param mixed $value
     * @return list<string>
     */
    private function names(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $name): bool => is_string($name) && $name !== ''));
    }
}
