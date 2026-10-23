<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\PersonalData;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * The keys the store lists as personal data, from the setting Personal Data Fields
 *
 * The setting holds one pattern per line; `*` stands for any run of characters. A key and a pattern are compared in
 * their normalised form, lower case with only letters, digits (and the pattern's `*`) kept, so one pattern covers a
 * REST field, its GraphQL spelling and a prefixed variant: `*firstname` matches `firstname`, `customer_firstname`
 * and `customerFirstname`. The list is compiled again whenever the setting's text changes.
 */
class PersonalFields
{
    public const XML_PATH_FIELDS = 'hryvinskyi_mcp_server/personal_data/fields';

    /**
     * @var string|null The setting's text the expression was compiled from
     */
    private ?string $compiledFrom = null;

    /**
     * @var string|null One expression for every pattern; null when the list is empty
     */
    private ?string $expression = null;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether a value under this key is personal data
     *
     * @param string $key
     * @return bool
     */
    public function isPersonal(string $key): bool
    {
        $expression = $this->expression();
        $normalised = $this->normalised($key);

        return $expression !== null && $normalised !== '' && preg_match($expression, $normalised) === 1;
    }

    /**
     * The compiled patterns of the setting as it reads now
     *
     * @return string|null
     */
    private function expression(): ?string
    {
        $text = $this->scopeConfig->getValue(self::XML_PATH_FIELDS);
        $text = is_string($text) ? $text : '';
        if ($text === $this->compiledFrom) {
            return $this->expression;
        }

        $alternatives = [];
        foreach (preg_split('/[\r\n,]+/', $text) ?: [] as $line) {
            $pattern = preg_replace('/[^a-z0-9*]/', '', mb_strtolower($line)) ?? '';
            if (trim($pattern, '*') !== '') {
                $alternatives[] = str_replace('\*', '.*', preg_quote($pattern, '/'));
            }
        }

        $this->compiledFrom = $text;

        return $this->expression = $alternatives === []
            ? null
            : '/^(?:' . implode('|', array_unique($alternatives)) . ')$/';
    }

    /**
     * The key in lower case with only its letters and digits
     *
     * @param string $key
     * @return string
     */
    private function normalised(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($key)) ?? '';
    }
}
