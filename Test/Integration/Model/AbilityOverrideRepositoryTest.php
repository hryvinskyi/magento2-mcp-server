<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Model\AbilityOverride;
use Hryvinskyi\McpServer\Model\AbilityOverrideRepository;
use Hryvinskyi\McpServer\Model\ResourceModel\AbilityOverride as AbilityOverrideResource;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The override table through the repository the container builds
 */
#[DbIsolation(true)]
class AbilityOverrideRepositoryTest extends TestCase
{
    use BlocksRealGraphQlClient;

    private const NAME = 'catalog.product_repository.save';
    private const OTHER_NAME = 'catalog.product_repository.delete_by_id';

    /**
     * @var AbilityOverrideRepository
     */
    private AbilityOverrideRepository $repository;

    /**
     * The repository is shared and keeps what it read; a previous test's rows were rolled back under it
     *
     * @return void
     */
    protected function setUp(): void
    {
        $repository = Bootstrap::getObjectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepository::class, $repository);
        $repository->_resetState();
        $this->repository = $repository;
    }

    public function testASavedOverrideComesBack(): void
    {
        $this->repository->save($this->override(self::NAME, true, false));
        $this->repository->save($this->override(self::OTHER_NAME, false, true));

        $denied = $this->repository->get(self::NAME);
        self::assertInstanceOf(AbilityOverride::class, $denied);
        self::assertSame(self::NAME, $denied->getAbility());
        self::assertTrue($denied->isDenied());
        self::assertFalse($denied->needsConfirmation());

        $confirmed = $this->repository->get(self::OTHER_NAME);
        self::assertNotNull($confirmed);
        self::assertFalse($confirmed->isDenied());
        self::assertTrue($confirmed->needsConfirmation());

        self::assertSame([self::OTHER_NAME, self::NAME], $this->sortedKeys($this->repository->all()));
        self::assertSame(['deny' => '1', 'confirm' => '0'], $this->storedFlags(self::NAME));
    }

    public function testSavingAgainUpdatesTheSameRow(): void
    {
        $this->repository->save($this->override(self::NAME, true, false));
        $this->repository->save($this->override(self::NAME, true, true));

        $override = $this->repository->get(self::NAME);
        self::assertNotNull($override);
        self::assertTrue($override->isDenied());
        self::assertTrue($override->needsConfirmation());
        self::assertSame(['deny' => '1', 'confirm' => '1'], $this->storedFlags(self::NAME));
    }

    public function testAnOverrideThatClearsBothFlagsDeletesTheRow(): void
    {
        $this->repository->save($this->override(self::NAME, true, true));

        $this->repository->save($this->override(self::NAME, false, false));

        self::assertNull($this->repository->get(self::NAME));
        self::assertArrayNotHasKey(self::NAME, $this->repository->all());
        self::assertNull($this->storedFlags(self::NAME));
    }

    public function testAnOverrideWithNeitherFlagIsNeverStored(): void
    {
        $this->repository->save($this->override(self::NAME, false, false));

        self::assertNull($this->repository->get(self::NAME));
        self::assertNull($this->storedFlags(self::NAME));
    }

    public function testDeleteRemovesTheOverride(): void
    {
        $this->repository->save($this->override(self::NAME, true, false));

        $this->repository->delete(self::NAME);

        self::assertNull($this->repository->get(self::NAME));
        self::assertNull($this->storedFlags(self::NAME));
    }

    public function testDeletingAnAbilityWithoutAnOverrideChangesNothing(): void
    {
        $this->repository->save($this->override(self::NAME, true, false));

        $this->repository->delete(self::OTHER_NAME);

        self::assertNotNull($this->repository->get(self::NAME));
        self::assertNull($this->repository->get(self::OTHER_NAME));
    }

    public function testAnOverrideOfSomethingThatIsNoAbilityNameIsRefused(): void
    {
        $this->expectException(CouldNotSaveException::class);

        $this->repository->save($this->override('Catalog.ProductRepository.save', true, false));
    }

    public function testTheTableIsReadOncePerRequest(): void
    {
        self::assertNull($this->repository->get(self::NAME));

        $this->connection()->insert(
            $this->table(),
            [AbilityOverrideInterface::ABILITY => self::NAME, AbilityOverrideInterface::DENY => 1]
        );

        self::assertNull(
            $this->repository->get(self::NAME),
            'A second read in the same request reads the table again.'
        );

        $this->repository->_resetState();

        $override = $this->repository->get(self::NAME);
        self::assertNotNull($override);
        self::assertTrue($override->isDenied());
    }

    /**
     * @param string $ability
     * @param bool $denied
     * @param bool $needsConfirmation
     * @return AbilityOverrideInterface
     */
    private function override(string $ability, bool $denied, bool $needsConfirmation): AbilityOverrideInterface
    {
        $override = Bootstrap::getObjectManager()->create(AbilityOverrideInterface::class);
        self::assertInstanceOf(AbilityOverrideInterface::class, $override);

        return $override
            ->setAbility($ability)
            ->setDenied($denied)
            ->setNeedsConfirmation($needsConfirmation);
    }

    /**
     * The flags as the table holds them, or null when the ability has no row
     *
     * @param string $ability
     * @return array{deny: string, confirm: string}|null
     */
    private function storedFlags(string $ability): ?array
    {
        $row = $this->connection()->fetchRow(
            $this->connection()->select()
                ->from($this->table(), [AbilityOverrideInterface::DENY, AbilityOverrideInterface::CONFIRM])
                ->where(AbilityOverrideInterface::ABILITY . ' = ?', $ability)
        );
        if (!is_array($row)) {
            return null;
        }

        $deny = $row[AbilityOverrideInterface::DENY] ?? null;
        $confirm = $row[AbilityOverrideInterface::CONFIRM] ?? null;
        self::assertIsString($deny);
        self::assertIsString($confirm);

        return ['deny' => $deny, 'confirm' => $confirm];
    }

    /**
     * @param array<string, AbilityOverrideInterface> $overrides
     * @return list<string>
     */
    private function sortedKeys(array $overrides): array
    {
        $keys = array_keys($overrides);
        sort($keys);

        return $keys;
    }

    /**
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);

        return $resource->getConnection();
    }

    /**
     * @return string
     */
    private function table(): string
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);

        return $resource->getTableName(AbilityOverrideResource::TABLE);
    }
}
