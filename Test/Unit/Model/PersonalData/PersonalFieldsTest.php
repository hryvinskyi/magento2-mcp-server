<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\PersonalData;

use Hryvinskyi\McpServer\Model\PersonalData\PersonalFields;
use Hryvinskyi\McpServer\Test\Unit\Fake\ArrayScopeConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which keys the setting Personal Data Fields makes personal
 */
class PersonalFieldsTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function keys(): array
    {
        return [
            'the field itself' => ['firstname', true],
            'a prefixed REST field' => ['customer_firstname', true],
            'its GraphQL spelling' => ['customerFirstname', true],
            'any case and signs' => ['Customer-First_Name', true],
            'a pattern without a wildcard is the whole key' => ['city_id', false],
            'the whole key' => ['city', true],
            'a field that only starts like one' => ['firstname_hint', false],
            'not listed' => ['sku', false],
            'a key with nothing to compare' => ['__', false],
        ];
    }

    #[DataProvider('keys')]
    public function testAKeyIsPersonalWhenItsNormalisedFormMatchesAPattern(string $key, bool $personal): void
    {
        $fields = new PersonalFields(new ArrayScopeConfig([
            PersonalFields::XML_PATH_FIELDS => "*firstname\n  City \n\n*",
        ]));

        self::assertSame($personal, $fields->isPersonal($key));
    }

    public function testTheListFollowsTheSettingWhenItChanges(): void
    {
        $config = new ArrayScopeConfig([PersonalFields::XML_PATH_FIELDS => '*email']);
        $fields = new PersonalFields($config);
        self::assertTrue($fields->isPersonal('customer_email'));

        $config->values[PersonalFields::XML_PATH_FIELDS] = 'telephone';

        self::assertSame([false, true], [$fields->isPersonal('customer_email'), $fields->isPersonal('telephone')]);
    }

    public function testAnEmptySettingMakesNothingPersonal(): void
    {
        $fields = new PersonalFields(new ArrayScopeConfig());

        self::assertFalse($fields->isPersonal('email'));
    }

    public function testASignInAPatternCannotWidenIt(): void
    {
        $fields = new PersonalFields(new ArrayScopeConfig([PersonalFields::XML_PATH_FIELDS => 'e.ail|sku']));

        self::assertSame(
            [false, false, true],
            [$fields->isPersonal('email'), $fields->isPersonal('sku'), $fields->isPersonal('eailsku')]
        );
    }
}
