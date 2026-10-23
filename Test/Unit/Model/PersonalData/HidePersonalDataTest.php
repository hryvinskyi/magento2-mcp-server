<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\PersonalData;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\PersonalData\HidePersonalData;
use Hryvinskyi\McpServer\Model\PersonalData\PersonalFields;
use Hryvinskyi\McpServer\Test\Unit\Fake\ArrayScopeConfig;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakePersonalDataPolicy;
use PHPUnit\Framework\TestCase;

/**
 * What of a result reaches the client while personal data is hidden
 */
class HidePersonalDataTest extends TestCase
{
    private const HIDDEN = '[personal data]';
    private const ABILITY = 'sales.order_repository.get';

    public function testEveryValueUnderAPersonalKeyIsHiddenHoweverDeepAndTheRestIsKept(): void
    {
        $order = [
            'entity_id' => 7,
            'customer_email' => 'ann@example.com',
            'customer_firstname' => 'Ann',
            'customer_middlename' => null,
            'grand_total' => 99.5,
            'billing_address' => [
                'firstname' => 'Ann',
                'street' => ['Main street 1', 'Flat 2'],
                'postcode' => 10115,
                'country_id' => 'DE',
            ],
            'items' => [['sku' => 'mug', 'qty_ordered' => 2]],
        ];

        self::assertSame(
            [
                'entity_id' => 7,
                'customer_email' => self::HIDDEN,
                'customer_firstname' => self::HIDDEN,
                'customer_middlename' => null,
                'grand_total' => 99.5,
                'billing_address' => [
                    'firstname' => self::HIDDEN,
                    'street' => [self::HIDDEN, self::HIDDEN],
                    'postcode' => self::HIDDEN,
                    'country_id' => 'DE',
                ],
                'items' => [['sku' => 'mug', 'qty_ordered' => 2]],
            ],
            $this->filter()->filter($this->ability(), $order)
        );
    }

    public function testACustomAttributeIsJudgedByItsCode(): void
    {
        $customer = ['custom_attributes' => [
            ['attribute_code' => 'mobile_phone', 'value' => '+49 30 1234'],
            ['attribute_code' => 'loyalty_tier', 'value' => 'gold'],
        ]];

        self::assertSame(
            ['custom_attributes' => [
                ['attribute_code' => 'mobile_phone', 'value' => self::HIDDEN],
                ['attribute_code' => 'loyalty_tier', 'value' => 'gold'],
            ]],
            $this->filter()->filter($this->ability(), $customer)
        );
    }

    public function testAnObjectIsReadAsItsJsonWouldBe(): void
    {
        $serializable = new class implements \JsonSerializable {
            /**
             * @return array<string, string>
             */
            public function jsonSerialize(): array
            {
                return ['email' => 'ann@example.com', 'status' => 'active'];
            }
        };
        $plain = new \stdClass();
        $plain->telephone = '030 1234';

        self::assertSame(
            [['email' => self::HIDDEN, 'status' => 'active'], ['telephone' => self::HIDDEN]],
            $this->filter()->filter($this->ability(), [$serializable, $plain])
        );
    }

    public function testAScalarResultHasNoKeyAndIsKept(): void
    {
        self::assertSame('ann@example.com', $this->filter()->filter($this->ability(), 'ann@example.com'));
    }

    public function testAnAbilityShownPersonalDataGetsItsResultAsItIs(): void
    {
        $result = ['email' => 'ann@example.com'];

        self::assertSame(
            $result,
            $this->filter(new FakePersonalDataPolicy(true, [self::ABILITY]))->filter($this->ability(), $result)
        );
        self::assertSame(
            $result,
            $this->filter(new FakePersonalDataPolicy(false))->filter($this->ability(), $result)
        );
    }

    /**
     * @param FakePersonalDataPolicy|null $policy
     * @return HidePersonalData
     */
    private function filter(?FakePersonalDataPolicy $policy = null): HidePersonalData
    {
        return new HidePersonalData(
            $policy ?? new FakePersonalDataPolicy(),
            new PersonalFields(new ArrayScopeConfig([
                PersonalFields::XML_PATH_FIELDS => "*email\n*firstname\n*middlename\n*street\n*postcode\n*phone",
            ]))
        );
    }

    /**
     * @return Ability
     */
    private function ability(): Ability
    {
        return new Ability(
            self::ABILITY,
            'Get an order',
            'Magento_Sales',
            'webapi',
            'GET /V1/orders/:id',
            AbilityAccess::Read,
            ['Magento_Sales::actions_view']
        );
    }
}
