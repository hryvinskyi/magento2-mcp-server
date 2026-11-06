<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model;

use Hryvinskyi\McpServer\Api\AbilitySourceInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterfaceFactory;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The ability catalog's index over its sources: lookup, search, paging, collisions, delegation to the owning source
 */
class AbilityCatalogTest extends TestCase
{
    /**
     * @var RecordingLogger
     */
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testGetFindsAnAbilityOfAnySource(): void
    {
        $storeViews = $this->ability('store.store_repository.get_list', 'webapi');
        $orders = $this->ability('sales.order_repository.get', 'other');
        $catalog = $this->catalog([
            'webapi' => new FakeAbilitySource('webapi', [$storeViews]),
            'other' => new FakeAbilitySource('other', [$orders]),
        ]);

        self::assertSame($storeViews, $catalog->get('store.store_repository.get_list'));
        self::assertSame($orders, $catalog->get('sales.order_repository.get'));
    }

    public function testGetThrowsForAnUnknownName(): void
    {
        $catalog = $this->catalog(['webapi' => new FakeAbilitySource('webapi', [])]);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('There is no ability named "store.nothing.here".');

        $catalog->get('store.nothing.here');
    }

    public function testTheSourcesAreListedOncePerRequestAndAgainAfterAReset(): void
    {
        $source = new FakeAbilitySource('webapi', [$this->ability('store.store_repository.get', 'webapi')]);
        $catalog = $this->catalog(['webapi' => $source]);

        $catalog->get('store.store_repository.get');
        $catalog->search('', null, null, 10, 0);
        $catalog->isAllowed($catalog->get('store.store_repository.get'));
        self::assertSame(1, $source->listings);

        $catalog->_resetState();
        $catalog->get('store.store_repository.get');
        self::assertSame(2, $source->listings);
    }

    public function testOnACollisionTheFirstSourceKeepsTheNameAndTheOtherIsLogged(): void
    {
        $first = $this->ability('store.store_repository.get', 'webapi', 'From the first source');
        $second = $this->ability('store.store_repository.get', 'other', 'From the second source');
        $firstSource = new FakeAbilitySource('webapi', [$first]);
        $secondSource = new FakeAbilitySource('other', [$second]);
        $catalog = $this->catalog(['webapi' => $firstSource, 'other' => $secondSource]);

        self::assertSame($first, $catalog->get('store.store_repository.get'));
        self::assertSame($firstSource, $catalog->sourceOf($first));
        self::assertSame('webapi', $catalog->schemaOf($first)['description']);
        self::assertSame(1, $catalog->search('', null, null, 10, 0)->getTotal());
        self::assertSame(
            [
                'MCP ability "store.store_repository.get" of source "other" is skipped: '
                . 'source "webapi" already offers an ability of that name.',
            ],
            $this->logger->messages('warning')
        );
    }

    public function testTheLosingAbilityOfACollisionIsOfferedByNoSource(): void
    {
        $catalog = $this->catalog([
            'webapi' => new FakeAbilitySource('webapi', [$this->ability('store.store_repository.get', 'webapi')]),
            'other' => new FakeAbilitySource('other', [$this->ability('store.store_repository.get', 'other')]),
        ]);

        $this->expectException(NoSuchEntityException::class);

        $catalog->schemaOf($this->ability('store.store_repository.get', 'other'));
    }

    public function testAnAbilityThatNamesAnotherSourceIsSkippedAndLogged(): void
    {
        $catalog = $this->catalog([
            'webapi' => new FakeAbilitySource('webapi', [
                $this->ability('store.store_repository.get', 'graphql'),
                $this->ability('store.store_repository.save', 'webapi'),
            ]),
        ]);

        self::assertSame(['store.store_repository.save'], $this->names($catalog->search('', null, null, 10, 0)));
        self::assertSame(
            [
                'MCP ability "store.store_repository.get" is skipped: source "webapi" listed it, '
                . 'but the ability names source "graphql".',
            ],
            $this->logger->messages('warning')
        );
    }

    public function testSchemaAndAclAreAskedOfTheOwningSource(): void
    {
        $allowed = $this->ability('store.store_repository.get', 'webapi');
        $refused = $this->ability('sales.order_repository.get', 'other');
        $catalog = $this->catalog([
            'webapi' => new FakeAbilitySource('webapi', [$allowed], true),
            'other' => new FakeAbilitySource('other', [$refused], false),
        ]);

        self::assertTrue($catalog->isAllowed($allowed));
        self::assertFalse($catalog->isAllowed($refused));
        self::assertSame('webapi', $catalog->schemaOf($allowed)['description']);
        self::assertSame('other', $catalog->schemaOf($refused)['description']);
    }

    public function testAnAbilityNoSourceListedIsRefused(): void
    {
        $catalog = $this->catalog(['webapi' => new FakeAbilitySource('webapi', [])]);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage(
            'No source offers the ability "store.store_repository.get" of source "webapi".'
        );

        $catalog->isAllowed($this->ability('store.store_repository.get', 'webapi'));
    }

    public function testAnEmptyQueryListsEveryAbilitySortedByName(): void
    {
        $catalog = $this->searchableCatalog();

        $page = $catalog->search('', null, null, 100, 0);

        self::assertSame(5, $page->getTotal());
        self::assertSame(
            [
                'directory.country_information_acquirer.get_countries_info',
                'sales.order_repository.save',
                'store.store_repository.delete_by_id',
                'store.store_repository.get_list',
                'store.website_repository.get',
            ],
            $this->names($page)
        );
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function queries(): array
    {
        return [
            'a word of the name' => ['country', ['directory.country_information_acquirer.get_countries_info']],
            'a word of the title, in another case' => ['ORDER', ['sales.order_repository.save']],
            'a word of the category' => ['magento_directory', [
                'directory.country_information_acquirer.get_countries_info',
            ]],
            'two words, both required' => ['view list', ['store.store_repository.get_list']],
            'two words from different fields' => ['magento_store delete', [
                'store.store_repository.delete_by_id',
            ]],
            'surrounding whitespace' => ["  website\t", ['store.website_repository.get']],
            'a word nothing holds' => ['invoice', []],
            'a word only the source reference holds' => ['v1', []],
        ];
    }

    /**
     * @param string $query
     * @param list<string> $expected
     * @return void
     */
    #[DataProvider('queries')]
    public function testTheQueryMatchesNameTitleAndCategoryIgnoringCase(string $query, array $expected): void
    {
        $page = $this->searchableCatalog()->search($query, null, null, 100, 0);

        self::assertSame($expected, $this->names($page));
        self::assertSame(count($expected), $page->getTotal());
    }

    public function testTheCategoryFilterIgnoresCase(): void
    {
        $page = $this->searchableCatalog()->search('', 'magento_store', null, 100, 0);

        self::assertSame(
            [
                'store.store_repository.delete_by_id',
                'store.store_repository.get_list',
                'store.website_repository.get',
            ],
            $this->names($page)
        );
    }

    public function testTheAccessFilterKeepsOnlyThatAccess(): void
    {
        $catalog = $this->searchableCatalog();

        self::assertSame(
            ['sales.order_repository.save'],
            $this->names($catalog->search('', null, AbilityAccess::Write, 100, 0))
        );
        self::assertSame(
            ['store.store_repository.delete_by_id'],
            $this->names($catalog->search('', 'Magento_Store', AbilityAccess::Delete, 100, 0))
        );
    }

    public function testFiltersAndQueryCombine(): void
    {
        $page = $this->searchableCatalog()->search('repository', 'Magento_Store', AbilityAccess::Read, 100, 0);

        self::assertSame(
            ['store.store_repository.get_list', 'store.website_repository.get'],
            $this->names($page)
        );
    }

    public function testPagesSliceTheSortedMatchesAndKeepTheTotal(): void
    {
        $catalog = $this->searchableCatalog();

        $first = $catalog->search('', null, null, 2, 0);
        $second = $catalog->search('', null, null, 2, 2);
        $last = $catalog->search('', null, null, 2, 4);
        $beyond = $catalog->search('', null, null, 2, 10);

        self::assertSame(
            ['directory.country_information_acquirer.get_countries_info', 'sales.order_repository.save'],
            $this->names($first)
        );
        self::assertSame(
            ['store.store_repository.delete_by_id', 'store.store_repository.get_list'],
            $this->names($second)
        );
        self::assertSame(['store.website_repository.get'], $this->names($last));
        self::assertSame([], $this->names($beyond));
        foreach ([$first, $second, $last, $beyond] as $page) {
            self::assertSame(5, $page->getTotal());
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidPaging(): array
    {
        return [
            'a limit of zero' => [0, 0],
            'a negative limit' => [-1, 0],
            'a negative offset' => [10, -1],
        ];
    }

    #[DataProvider('invalidPaging')]
    public function testInvalidPagingIsRefused(int $limit, int $offset): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->searchableCatalog()->search('', null, null, $limit, $offset);
    }

    public function testACatalogWithoutSourcesIsEmpty(): void
    {
        $page = $this->catalog([])->search('', null, null, 25, 0);

        self::assertSame(0, $page->getTotal());
        self::assertSame([], $page->getItems());
    }

    /**
     * An ability catalog of five abilities from two sources, listed out of order
     *
     * @return AbilityCatalog
     */
    private function searchableCatalog(): AbilityCatalog
    {
        return $this->catalog([
            'webapi' => new FakeAbilitySource('webapi', [
                $this->ability(
                    'sales.order_repository.save',
                    'webapi',
                    'Save an order',
                    'Magento_Sales',
                    AbilityAccess::Write
                ),
                $this->ability('store.store_repository.get_list', 'webapi', 'Store view list', 'Magento_Store'),
                $this->ability(
                    'store.store_repository.delete_by_id',
                    'webapi',
                    'Remove a store view',
                    'Magento_Store',
                    AbilityAccess::Delete
                ),
            ]),
            'other' => new FakeAbilitySource('other', [
                $this->ability(
                    'directory.country_information_acquirer.get_countries_info',
                    'other',
                    'Countries',
                    'Magento_Directory'
                ),
                $this->ability('store.website_repository.get', 'other', 'One website', 'Magento_Store'),
            ]),
        ]);
    }

    /**
     * @param array<string, AbilitySourceInterface> $sources
     * @return AbilityCatalog
     */
    private function catalog(array $sources): AbilityCatalog
    {
        $pageFactory = new AbilityPageInterfaceFactory(
            new ConstructingObjectManager([AbilityPageInterface::class => AbilityPage::class])
        );

        return new AbilityCatalog($pageFactory, $this->logger, $sources);
    }

    /**
     * @param string $name
     * @param string $source
     * @param string $title
     * @param string $category
     * @param AbilityAccess $access
     * @return AbilityInterface
     */
    private function ability(
        string $name,
        string $source,
        string $title = 'Ability',
        string $category = 'Magento_Store',
        AbilityAccess $access = AbilityAccess::Read
    ): AbilityInterface {
        return new Ability($name, $title, $category, $source, 'GET /V1/' . $name, $access);
    }

    /**
     * @param AbilityPageInterface $page
     * @return list<string>
     */
    private function names(AbilityPageInterface $page): array
    {
        return array_map(static fn (AbilityInterface $ability): string => $ability->getName(), $page->getItems());
    }
}
