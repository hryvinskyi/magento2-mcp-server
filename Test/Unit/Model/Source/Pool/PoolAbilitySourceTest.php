<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Source\Pool;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\PoolAbilityInterface;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Source\Pool\InputSchemaNormaliser;
use Hryvinskyi\McpServer\Model\Source\Pool\PoolAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAuthorization;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakePoolAbility;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Webapi\Authorization;
use PHPUnit\Framework\TestCase;

/**
 * The source over the registered items: which become abilities, which are skipped, and how each is asked
 */
class PoolAbilitySourceTest extends TestCase
{
    private const QUOTE = 'acme_shipping.rate_service.quote';

    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    /**
     * @var FakeAuthorization
     */
    private FakeAuthorization $acl;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->acl = new FakeAuthorization(['Magento_Catalog::products']);
    }

    public function testAnItemBecomesAnAbilityOfThePoolWithItsOwnValues(): void
    {
        $source = $this->source([
            self::QUOTE => new FakePoolAbility(self::QUOTE, ['Magento_Sales::sales'], AbilityAccess::Write),
        ]);

        $abilities = $this->listed($source);

        self::assertSame('pool', $source->getCode());
        self::assertCount(1, $abilities);
        $ability = $abilities[0];
        self::assertSame(self::QUOTE, $ability->getName());
        self::assertSame('pool', $ability->getSource());
        self::assertSame('pool:' . self::QUOTE, $ability->getSourceReference());
        self::assertSame('Quote a shipment', $ability->getTitle());
        self::assertSame('Quotes a shipment for a destination.', $ability->getDescription());
        self::assertSame('Acme_Shipping', $ability->getCategory());
        self::assertSame(AbilityAccess::Write, $ability->getAccess());
        self::assertSame(['Magento_Sales::sales'], $ability->getAclResources());
        self::assertSame([], $this->logger->messages('warning'));
    }

    public function testAnItemThatIsNotAPoolAbilityIsSkippedAndTheOthersStayListed(): void
    {
        $source = $this->source([
            'acme_shipping.rate_service.broken' => new \stdClass(),
            self::QUOTE => new FakePoolAbility(self::QUOTE),
        ]);

        self::assertSame([self::QUOTE], $this->names($source));
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('"acme_shipping.rate_service.broken"', $warnings[0]);
        self::assertStringContainsString('stdClass', $warnings[0]);
    }

    public function testAnItemWhoseKeyIsNotItsNameIsSkipped(): void
    {
        $source = $this->source([
            'acme_shipping.rate_service.estimate' => new FakePoolAbility(self::QUOTE),
            self::QUOTE => new FakePoolAbility(self::QUOTE),
        ]);

        self::assertSame([self::QUOTE], $this->names($source));
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('"acme_shipping.rate_service.estimate"', $warnings[0]);
        self::assertStringContainsString('its key is not its name', $warnings[0]);
    }

    public function testAnItemWithoutAclResourcesIsSkippedSoNoCallerIsLetThroughByAnEmptyList(): void
    {
        $source = $this->source([
            self::QUOTE => new FakePoolAbility(self::QUOTE, []),
        ]);

        self::assertSame([], $this->names($source));
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('names no ACL resource', $warnings[0]);
        self::assertSame([], $this->acl->asked);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function brokenAclLists(): array
    {
        return [
            'a number' => [[42]],
            'null' => [[null]],
            'a blank string' => [['Magento_Sales::sales', ' ']],
            'a list' => [[['Magento_Sales::sales']]],
        ];
    }

    /**
     * @param array<mixed> $aclResources
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenAclLists')]
    public function testAnItemNamingAnAclResourceThatIsNotANonEmptyStringIsSkipped(array $aclResources): void
    {
        $broken = $this->createStub(PoolAbilityInterface::class);
        $broken->method('getName')->willReturn('acme_shipping.rate_service.broken');
        $broken->method('getAclResources')->willReturn($aclResources);
        $source = $this->source([
            'acme_shipping.rate_service.broken' => $broken,
            self::QUOTE => new FakePoolAbility(self::QUOTE),
        ]);

        self::assertSame([self::QUOTE], $this->names($source));
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('"acme_shipping.rate_service.broken"', $warnings[0]);
        self::assertStringContainsString('not a non-empty string', $warnings[0]);
    }

    public function testAnItemWhoseGetterFailsInAnyWayIsSkippedAndTheOthersStayListed(): void
    {
        $failing = $this->createStub(PoolAbilityInterface::class);
        $failing->method('getName')->willReturn('acme_shipping.rate_service.failing');
        $failing->method('getAclResources')->willReturn(['Magento_Sales::sales']);
        $failing->method('getTitle')->willThrowException(new LocalizedException(__('The carrier answered no title.')));
        $erroring = $this->createStub(PoolAbilityInterface::class);
        $erroring->method('getName')->willThrowException(new \Error('Call to a member function on null'));
        $source = $this->source([
            'acme_shipping.rate_service.failing' => $failing,
            'acme_shipping.rate_service.erroring' => $erroring,
            self::QUOTE => new FakePoolAbility(self::QUOTE),
        ]);

        self::assertSame([self::QUOTE], $this->names($source));
        self::assertSame(
            [
                'MCP skips the registered ability "acme_shipping.rate_service.failing": The carrier answered no title.',
                'MCP skips the registered ability "acme_shipping.rate_service.erroring": '
                . 'Call to a member function on null.',
            ],
            $this->logger->messages('warning')
        );
    }

    public function testAnItemWhoseNameBreaksTheAbilityNameFormatIsSkipped(): void
    {
        $source = $this->source([
            'Acme.Quote' => new FakePoolAbility('Acme.Quote'),
            self::QUOTE => new FakePoolAbility(self::QUOTE),
        ]);

        self::assertSame([self::QUOTE], $this->names($source));
        $warnings = $this->logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('"Acme.Quote"', $warnings[0]);
        self::assertStringContainsString('<module>.<service>.<method>', $warnings[0]);
    }

    public function testAnItemWithABlankCategoryIsSkipped(): void
    {
        $source = $this->source([
            self::QUOTE => new FakePoolAbility(self::QUOTE, category: ' '),
        ]);

        self::assertSame([], $this->names($source));
        self::assertStringContainsString('needs a category', $this->logger->messages('warning')[0]);
    }

    public function testAnItemThatRefusesItsOwnValuesIsSkipped(): void
    {
        $source = $this->source([
            self::QUOTE => new FakePoolAbility(self::QUOTE, refusal: 'The carrier is not configured.'),
        ]);

        self::assertSame([], $this->names($source));
        self::assertSame(
            ['MCP skips the registered ability "' . self::QUOTE . '": The carrier is not configured.'],
            $this->logger->messages('warning')
        );
    }

    public function testTheAbilitiesAreListedOncePerRequestAndAgainAfterAReset(): void
    {
        $item = new FakePoolAbility(self::QUOTE);
        $source = $this->source([self::QUOTE => $item]);

        $ability = $this->listed($source)[0];
        $this->listed($source);
        $source->inputSchema($ability);
        $source->isAllowed($ability);
        $source->invoke($ability, []);
        self::assertSame(1, $item->nameReads);

        $source->_resetState();
        $this->listed($source);
        self::assertSame(2, $item->nameReads);
    }

    public function testTheAclAsksForEveryResourceOfTheAbility(): void
    {
        $source = $this->source([
            self::QUOTE => new FakePoolAbility(self::QUOTE, ['Magento_Catalog::products']),
            'acme_shipping.rate_service.book' => new FakePoolAbility(
                'acme_shipping.rate_service.book',
                ['Magento_Catalog::products', 'Magento_Sales::sales']
            ),
        ]);
        [$quote, $book] = $this->listed($source);

        self::assertTrue($source->isAllowed($quote));
        self::assertFalse($source->isAllowed($book));
        self::assertSame(
            ['Magento_Catalog::products', 'Magento_Catalog::products', 'Magento_Sales::sales'],
            $this->acl->asked
        );
    }

    public function testTheSchemaKeepsEmptyObjectsAsObjects(): void
    {
        $source = $this->source([
            self::QUOTE => new FakePoolAbility(self::QUOTE, inputSchema: [
                'type' => 'object',
                'properties' => [
                    'destination' => ['type' => 'object', 'properties' => []],
                    'parcels' => ['type' => 'array', 'items' => []],
                ],
                'required' => [],
            ]),
        ]);

        $schema = $source->inputSchema($this->listed($source)[0]);

        self::assertSame(
            '{"type":"object","properties":{"destination":{"type":"object","properties":{}},'
            . '"parcels":{"type":"array","items":{}}},"required":[]}',
            json_encode($schema, JSON_THROW_ON_ERROR)
        );
    }

    public function testACallRunsTheItemWithTheClientArguments(): void
    {
        $item = new FakePoolAbility(self::QUOTE);
        $source = $this->source([self::QUOTE => $item]);

        $answer = $source->invoke($this->listed($source)[0], ['country' => 'UA', 'weight' => 2.5]);

        self::assertSame(['asked' => ['country' => 'UA', 'weight' => 2.5]], $answer);
        self::assertSame([['country' => 'UA', 'weight' => 2.5]], $item->calls);
    }

    public function testAnAbilityTheSourceDoesNotOfferIsRefused(): void
    {
        $item = new FakePoolAbility(self::QUOTE);
        $source = $this->source([self::QUOTE => $item]);
        $this->listed($source);

        try {
            $source->invoke($this->ability(self::QUOTE, 'webapi'), []);
            self::fail('An ability of another source was run.');
        } catch (NoSuchEntityException) {
            self::assertSame([], $item->calls);
        }

        $this->expectException(NoSuchEntityException::class);
        $source->isAllowed($this->ability('acme_shipping.rate_service.book', 'pool'));
    }

    /**
     * @param string $name
     * @param string $source
     * @return AbilityInterface
     */
    private function ability(string $name, string $source): AbilityInterface
    {
        return new Ability($name, 'Title', 'Acme_Shipping', $source, 'pool:' . $name, AbilityAccess::Read, ['x']);
    }

    /**
     * @param PoolAbilitySource $source
     * @return list<string>
     */
    private function names(PoolAbilitySource $source): array
    {
        return array_map(static fn (AbilityInterface $ability): string => $ability->getName(), $this->listed($source));
    }

    /**
     * @param PoolAbilitySource $source
     * @return list<AbilityInterface>
     */
    private function listed(PoolAbilitySource $source): array
    {
        $abilities = [];
        foreach ($source->abilities() as $ability) {
            $abilities[] = $ability;
        }

        return $abilities;
    }

    /**
     * @param array<string, mixed> $abilities
     * @return PoolAbilitySource
     */
    private function source(array $abilities): PoolAbilitySource
    {
        return new PoolAbilitySource(
            new AbilityInterfaceFactory(new ConstructingObjectManager([AbilityInterface::class => Ability::class])),
            new InputSchemaNormaliser(),
            new Authorization($this->acl),
            $this->logger,
            $abilities
        );
    }
}
