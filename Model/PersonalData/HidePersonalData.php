<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\PersonalData;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;
use Hryvinskyi\McpServer\Api\ResultFilterInterface;

/**
 * Replaces the personal data in a result with PersonalDataPolicyInterface::HIDDEN, unless the policy shows it
 *
 * - Under a personal key every value is hidden, however deep it sits: a street's lines, an address object's fields.
 * - An attribute given as a pair (`attribute_code`, `value`), as custom attributes are, is judged by its code.
 * - `null` stays `null`: it tells nothing about anyone, and the client still sees the field is empty.
 * - An object is read as the client's JSON would read it: what it serializes to, or else its public properties.
 */
class HidePersonalData implements ResultFilterInterface
{
    private const ATTRIBUTE_CODE = 'attribute_code';
    private const ATTRIBUTE_VALUE = 'value';

    /**
     * @param PersonalDataPolicyInterface $policy
     * @param PersonalFields $personalFields
     */
    public function __construct(
        private readonly PersonalDataPolicyInterface $policy,
        private readonly PersonalFields $personalFields
    ) {
    }

    /**
     * @inheritDoc
     */
    public function filter(AbilityInterface $ability, mixed $value): mixed
    {
        return $this->policy->hidesFor($ability->getName()) ? $this->walk($value, false) : $value;
    }

    /**
     * The value with what is personal in it hidden
     *
     * @param mixed $value
     * @param bool $personal Whether the value sits under a personal key
     * @return mixed
     */
    private function walk(mixed $value, bool $personal): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $this->walk($value->jsonSerialize(), $personal);
        }
        if (is_object($value)) {
            return $this->walk(get_object_vars($value), $personal);
        }
        if (!is_array($value)) {
            return $personal && $value !== null ? PersonalDataPolicyInterface::HIDDEN : $value;
        }

        $attributeIsPersonal = $this->isPersonalAttribute($value);
        $walked = [];
        foreach ($value as $key => $item) {
            $walked[$key] = $this->walk(
                $item,
                $personal
                || (is_string($key) && $this->personalFields->isPersonal($key))
                || ($attributeIsPersonal && $key === self::ATTRIBUTE_VALUE)
            );
        }

        return $walked;
    }

    /**
     * Whether the array is an attribute pair whose code is personal
     *
     * @param array<mixed> $value
     * @return bool
     */
    private function isPersonalAttribute(array $value): bool
    {
        $code = $value[self::ATTRIBUTE_CODE] ?? null;

        return is_string($code)
            && array_key_exists(self::ATTRIBUTE_VALUE, $value)
            && $this->personalFields->isPersonal($code);
    }
}
