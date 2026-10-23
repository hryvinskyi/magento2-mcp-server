<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\PersonalData;

use Hryvinskyi\McpServer\Api\Data\InvocationOutcome;
use Hryvinskyi\McpServer\Api\PersonalDataExceptionsInterface;
use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\PersonalData\PersonalDataPolicy;
use Hryvinskyi\McpServer\Test\Integration\Fixture\ForgetsStoredNames;
use Hryvinskyi\McpServer\Test\Integration\Fixture\InvokesTestAbilities;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingListener;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Personal data in the results of the container's invoker, with the settings the module installs
 */
#[DbIsolation(true)]
class PersonalDataTest extends TestCase
{
    use ForgetsStoredNames;
    use InvokesTestAbilities;
    use SwitchesGraphQlSourceOff;

    private const ORDER = 'test.order.get';
    private const HIDDEN = '[personal data]';

    /**
     * @var RecordingListener
     */
    private RecordingListener $listener;

    /**
     * @var AbilityInvoker
     */
    private AbilityInvoker $invoker;

    protected function setUp(): void
    {
        $this->listener = new RecordingListener();
        $this->invoker = $this->invokerOver(
            new FakeAbilitySource(
                'test',
                [$this->readingAbility(self::ORDER, ['Magento_Config::config'])],
                true,
                static fn (): array => [
                    'increment_id' => '000000042',
                    'customer_email' => 'ann@example.com',
                    'customer_firstname' => 'Ann',
                    'billing_address' => [
                        'street' => ['Main street 1'],
                        'telephone' => '030 1234',
                        'country_id' => 'DE',
                    ],
                    'custom_attributes' => [['attribute_code' => 'customer_dob', 'value' => '1990-01-01']],
                    'customer_prefix' => 'Ms',
                    'payment' => [
                        'method' => 'checkmo',
                        'cc_owner' => 'Ann Smith',
                        'cc_last_4' => '4242',
                        'additional_information' => ['ann@example.com', 'Ann Smith'],
                    ],
                    'extension_attributes' => [
                        'gift_message' => ['sender' => 'Ann', 'recipient' => 'Bob', 'message' => 'Happy birthday'],
                    ],
                    'shipping_label' => 'a PDF with the address',
                ]
            ),
            $this->listener
        );
    }

    public function testAfterInstallTheClientAndTheListenersGetTheResultWithItsPersonalDataHidden(): void
    {
        $result = $this->invoker->invoke($this->invocation(self::ORDER));

        self::assertSame(InvocationOutcome::Succeeded, $result->getOutcome());
        self::assertSame(
            [
                'increment_id' => '000000042',
                'customer_email' => self::HIDDEN,
                'customer_firstname' => self::HIDDEN,
                'billing_address' => [
                    'street' => [self::HIDDEN],
                    'telephone' => self::HIDDEN,
                    'country_id' => 'DE',
                ],
                'custom_attributes' => [['attribute_code' => 'customer_dob', 'value' => self::HIDDEN]],
                'customer_prefix' => self::HIDDEN,
                'payment' => [
                    'method' => 'checkmo',
                    'cc_owner' => self::HIDDEN,
                    'cc_last_4' => self::HIDDEN,
                    'additional_information' => [self::HIDDEN, self::HIDDEN],
                ],
                'extension_attributes' => [
                    'gift_message' => [
                        'sender' => self::HIDDEN,
                        'recipient' => self::HIDDEN,
                        'message' => self::HIDDEN,
                    ],
                ],
                'shipping_label' => self::HIDDEN,
            ],
            $result->getValue()
        );
        self::assertSame([$result], $this->listener->results);
    }

    public function testAnAbilityShownPersonalDataGetsItAsItIs(): void
    {
        $this->exceptions()->show([self::ORDER]);

        $value = $this->invoker->invoke($this->invocation(self::ORDER))->getValue();

        self::assertIsArray($value);
        self::assertSame('ann@example.com', $value['customer_email'] ?? null);
        self::assertSame([self::ORDER], $this->exceptions()->shown());
    }

    public function testHidingItAgainHidesIt(): void
    {
        $this->exceptions()->show([self::ORDER]);
        $this->exceptions()->hide([self::ORDER]);

        $value = $this->invoker->invoke($this->invocation(self::ORDER))->getValue();

        self::assertIsArray($value);
        self::assertSame(self::HIDDEN, $value['customer_email'] ?? null);
    }

    #[ConfigFixture(PersonalDataPolicy::XML_PATH_HIDE, '0')]
    public function testWithHidingOffEveryResultKeepsItsPersonalData(): void
    {
        $value = $this->invoker->invoke($this->invocation(self::ORDER))->getValue();

        self::assertIsArray($value);
        self::assertSame('ann@example.com', $value['customer_email'] ?? null);
        $policy = Bootstrap::getObjectManager()->get(PersonalDataPolicyInterface::class);
        self::assertInstanceOf(PersonalDataPolicyInterface::class, $policy);
        self::assertFalse($policy->isHidingOn());
    }

    /**
     * @return PersonalDataExceptionsInterface
     */
    private function exceptions(): PersonalDataExceptionsInterface
    {
        $exceptions = Bootstrap::getObjectManager()->get(PersonalDataExceptionsInterface::class);
        self::assertInstanceOf(PersonalDataExceptionsInterface::class, $exceptions);

        return $exceptions;
    }
}
