<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * A stored set of names, one row each in a table whose key is the column `name`
 *
 * di.xml gives each set its own virtual type with its table: the sections switched off, the abilities shown
 * personal data. The set is read once per request and read again after it changes.
 */
class NameSet implements ResetAfterRequestInterface
{
    private const NAME = 'name';
    private const MAX_LENGTH = 255;

    /**
     * @var list<string>|null
     */
    private ?array $names = null;

    /**
     * @param ResourceConnection $resourceConnection
     * @param string $table
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly string $table
    ) {
    }

    /**
     * Every name in the set, in the order they sort
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $connection = $this->resourceConnection->getConnection();
        $names = $connection->fetchCol(
            $connection->select()->from($this->tableName(), [self::NAME])->order(self::NAME)
        );

        return $this->names = array_values(array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names
        ));
    }

    /**
     * Whether the name is in the set
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        return in_array($name, $this->all(), true);
    }

    /**
     * Put the names in the set; a name already there stays
     *
     * @param list<string> $names
     * @return void
     * @throws \InvalidArgumentException When a name is empty or longer than the column
     */
    public function add(array $names): void
    {
        $rows = [];
        foreach (array_unique($names) as $name) {
            if ($name === '' || mb_strlen($name) > self::MAX_LENGTH) {
                throw new \InvalidArgumentException(sprintf('"%s" cannot be stored as a name.', $name));
            }
            $rows[] = [self::NAME => $name];
        }
        if ($rows === []) {
            return;
        }

        try {
            $this->resourceConnection->getConnection()->insertOnDuplicate($this->tableName(), $rows, [self::NAME]);
        } finally {
            $this->names = null;
        }
    }

    /**
     * Take the names out of the set; a name not there is skipped
     *
     * @param list<string> $names
     * @return void
     */
    public function remove(array $names): void
    {
        if ($names === []) {
            return;
        }

        try {
            $this->resourceConnection->getConnection()->delete(
                $this->tableName(),
                [self::NAME . ' IN (?)' => array_values(array_unique($names))]
            );
        } finally {
            $this->names = null;
        }
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->names = null;
    }

    /**
     * The table's name with the installation's prefix
     *
     * @return string
     */
    private function tableName(): string
    {
        return $this->resourceConnection->getTableName($this->table);
    }
}
