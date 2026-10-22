<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\DataProvider;

use Hryvinskyi\McpServer\Model\Admin\AbilityGridQuery;
use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultFactory;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;

/**
 * The ability catalog grid's data: the catalog's abilities, searched, filtered, ordered and paged in memory
 *
 * The catalog is not a table: every source builds its abilities when asked, so the grid reads the whole catalog once
 * per request and applies the grid's search ("fulltext"), its filters, its order and its page to it. Abilities are
 * named, not numbered, so the search result carries the count alone; the grid and its mass actions read the rows
 * through getData() and matchingNames().
 *
 * @phpstan-import-type AbilityRow from AbilityRows
 * @phpstan-import-type Condition from AbilityGridQuery
 */
class AbilityDataProvider implements DataProviderInterface, MatchingAbilitiesInterface
{
    private const FULLTEXT = 'fulltext';

    /**
     * @var list<Condition>
     */
    private array $conditions = [];

    /**
     * @var string
     */
    private string $search = '';

    /**
     * @var string
     */
    private string $orderField = 'name';

    /**
     * @var bool
     */
    private bool $descending = false;

    /**
     * @var int
     */
    private int $offset = 1;

    /**
     * @var int|null
     */
    private ?int $size = null;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param AbilityRows $rows
     * @param AbilityGridQuery $query
     * @param SearchCriteriaBuilder $criteriaBuilder
     * @param SearchResultFactory $searchResultFactory
     * @param array<mixed> $meta
     * @param array<mixed> $data
     */
    public function __construct(
        private readonly string $name,
        private readonly string $primaryFieldName,
        private readonly string $requestFieldName,
        private readonly AbilityRows $rows,
        private readonly AbilityGridQuery $query,
        private readonly SearchCriteriaBuilder $criteriaBuilder,
        private readonly SearchResultFactory $searchResultFactory,
        private array $meta = [],
        private array $data = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getConfigData()
    {
        $config = $this->data['config'] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * @inheritDoc
     */
    public function setConfigData($config)
    {
        $this->data['config'] = $config;
    }

    /**
     * Nothing beyond the listing's own configuration
     *
     * @return array<mixed>
     */
    public function getMeta()
    {
        return $this->meta;
    }

    /**
     * Nothing beyond the listing's own configuration
     *
     * @param string $fieldSetName
     * @param string $fieldName
     * @return array<mixed>
     */
    public function getFieldMetaInfo($fieldSetName, $fieldName)
    {
        return [];
    }

    /**
     * Nothing beyond the listing's own configuration
     *
     * @param string $fieldSetName
     * @return array<mixed>
     */
    public function getFieldSetMetaInfo($fieldSetName)
    {
        return [];
    }

    /**
     * Nothing beyond the listing's own configuration
     *
     * @param string $fieldSetName
     * @return array<mixed>
     */
    public function getFieldsMetaInfo($fieldSetName)
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getPrimaryFieldName()
    {
        return $this->primaryFieldName;
    }

    /**
     * @inheritDoc
     */
    public function getRequestFieldName()
    {
        return $this->requestFieldName;
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        $rows = $this->ordered();

        return ['totalRecords' => count($rows), 'items' => $this->page($rows)];
    }

    /**
     * @inheritDoc
     */
    public function addFilter(Filter $filter)
    {
        $field = (string) $filter->getField();
        $value = $this->plain($filter->getValue());
        if ($field === self::FULLTEXT) {
            $this->search = is_string($value) ? $value : '';

            return;
        }

        $this->conditions[] = [
            'field' => $field,
            'condition' => (string) ($filter->getConditionType() ?: 'eq'),
            'value' => $value,
        ];
    }

    /**
     * @inheritDoc
     */
    public function addOrder($field, $direction)
    {
        $this->orderField = $field;
        $this->descending = strtoupper($direction) === 'DESC';
    }

    /**
     * @inheritDoc
     */
    public function setLimit($offset, $size)
    {
        $this->offset = max(1, $offset);
        $this->size = $size > 0 ? $size : null;
    }

    /**
     * @inheritDoc
     */
    public function getSearchCriteria()
    {
        return $this->criteriaBuilder->create();
    }

    /**
     * @inheritDoc
     */
    public function getSearchResult()
    {
        return $this->searchResultFactory->create()
            ->setItems([])
            ->setTotalCount(count($this->ordered()))
            ->setSearchCriteria($this->getSearchCriteria());
    }

    /**
     * @inheritDoc
     */
    public function matchingNames(): array
    {
        return array_column($this->ordered(), 'name');
    }

    /**
     * The rows of the page the grid asked for, or every row when it asked for no page size
     *
     * @param list<AbilityRow> $rows
     * @return list<AbilityRow>
     */
    private function page(array $rows): array
    {
        return $this->size === null
            ? $rows
            : array_slice($rows, max(0, ($this->offset - 1) * $this->size), $this->size);
    }

    /**
     * A filter's value as the query compares it: text, or a list of texts for "in" and "nin"
     *
     * @param mixed $value
     * @return string|list<string>
     */
    private function plain(mixed $value): string|array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', array_filter($value, 'is_scalar')));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Every row the search and filters select, in the grid's order
     *
     * @return list<AbilityRow>
     */
    private function ordered(): array
    {
        return $this->query->sorted(
            $this->query->matching($this->rows->all(), $this->conditions, $this->search),
            $this->orderField,
            $this->descending
        );
    }
}
