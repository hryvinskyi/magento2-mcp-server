<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model\Confirmation;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Model\Confirmation\ArgumentsFingerprint;
use Hryvinskyi\McpServer\Model\Confirmation\ConfirmationStoreInterface;
use Hryvinskyi\McpServer\Model\Confirmation\DbConfirmationStore;
use Hryvinskyi\McpServer\Test\Integration\Fake\FixedWallClock;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The confirmation table through the store the container builds, with the time of day under the test's control
 */
#[DbIsolation(true)]
class DbConfirmationStoreTest extends TestCase
{
    use BlocksRealGraphQlClient;

    private const START = 1_800_000_000;
    private const SESSION = 'abababababababababababababababab';
    private const ABILITY = 'catalog.product_repository.save';
    private const ARGUMENTS = ['product' => ['sku' => 'A1', 'price' => 1.5]];

    /**
     * @var FixedWallClock
     */
    private FixedWallClock $clock;

    /**
     * @var DbConfirmationStore
     */
    private DbConfirmationStore $store;

    protected function setUp(): void
    {
        $this->clock = new FixedWallClock(self::START);
        $store = Bootstrap::getObjectManager()->create(DbConfirmationStore::class, ['clock' => $this->clock]);
        self::assertInstanceOf(DbConfirmationStore::class, $store);
        $this->store = $store;
    }

    public function testTheContainerKeepsConfirmationTokensInTheDatabase(): void
    {
        self::assertInstanceOf(
            DbConfirmationStore::class,
            Bootstrap::getObjectManager()->get(ConfirmationStoreInterface::class)
        );
    }

    public function testAnIssuedTokenIsStoredBoundToTheCallForTenMinutes(): void
    {
        $confirmation = $this->store->issue(self::SESSION, $this->admin(7), self::ABILITY, self::ARGUMENTS);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $confirmation->getToken());
        self::assertSame(self::START + 600, $confirmation->getExpiresAt());
        $fingerprint = $this->service(ArgumentsFingerprint::class)->of(self::ARGUMENTS);
        self::assertSame(
            [
                'token' => $confirmation->getToken(),
                'session_id' => self::SESSION,
                'user_type' => (string) UserContextInterface::USER_TYPE_ADMIN,
                'user_id' => '7',
                'ability' => self::ABILITY,
                'arguments_hash' => $fingerprint,
                'expires_at' => '2027-01-15 08:10:00',
            ],
            $this->storedRow($confirmation->getToken())
        );
    }

    public function testEveryTokenIsNew(): void
    {
        $first = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS);
        $second = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS);

        self::assertNotSame($first->getToken(), $second->getToken());
    }

    public function testATokenConfirmsItsCallOnceWhateverTheOrderOfTheArgumentKeys(): void
    {
        $token = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS)->getToken();
        $reordered = ['product' => ['price' => 1.5, 'sku' => 'A1']];

        self::assertTrue($this->store->consume($token, self::SESSION, $this->admin(1), self::ABILITY, $reordered));
        self::assertFalse($this->store->consume($token, self::SESSION, $this->admin(1), self::ABILITY, $reordered));
        self::assertSame([], $this->storedRow($token));
    }

    /**
     * @return array<string, array{string, int, string, array<string, mixed>}>
     */
    public static function otherCalls(): array
    {
        return [
            'another session' => [str_repeat('cd', 16), 1, self::ABILITY, self::ARGUMENTS],
            'another caller' => [self::SESSION, 2, self::ABILITY, self::ARGUMENTS],
            'another ability' => [self::SESSION, 1, 'catalog.product_repository.delete', self::ARGUMENTS],
            'other arguments' => [self::SESSION, 1, self::ABILITY, ['product' => ['sku' => 'A2', 'price' => 1.5]]],
        ];
    }

    /**
     * @param string $sessionId
     * @param int $adminId
     * @param string $ability
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('otherCalls')]
    public function testATokenPresentedForAnotherCallConfirmsNothingAndIsSpent(
        string $sessionId,
        int $adminId,
        string $ability,
        array $arguments
    ): void {
        $token = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS)->getToken();

        self::assertFalse($this->store->consume($token, $sessionId, $this->admin($adminId), $ability, $arguments));
        self::assertFalse(
            $this->store->consume($token, self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS),
            'The token was spent by the call that presented it.'
        );
    }

    public function testATokenExpiresTenMinutesAfterItWasHandedOut(): void
    {
        $early = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS)->getToken();
        $late = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS)->getToken();

        $this->clock->now = self::START + 599;
        self::assertTrue($this->store->consume($early, self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS));

        $this->clock->now = self::START + 600;
        self::assertFalse($this->store->consume($late, self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS));
    }

    public function testAnUnknownOrMalformedTokenConfirmsNothing(): void
    {
        $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS);

        foreach ([str_repeat('0', 32), "' OR '1'='1", '', str_repeat('A', 32)] as $token) {
            self::assertFalse(
                $this->store->consume($token, self::SESSION, $this->admin(1), self::ABILITY, self::ARGUMENTS),
                $token
            );
        }
    }

    public function testDeleteExpiredRemovesOnlyExpiredTokensOldestFirstInBatches(): void
    {
        $oldest = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, [])->getToken();
        $this->clock->now = self::START + 10;
        $older = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, [])->getToken();
        $this->clock->now = self::START + 20;
        $old = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, [])->getToken();
        $this->clock->now = self::START + 300;
        $fresh = $this->store->issue(self::SESSION, $this->admin(1), self::ABILITY, [])->getToken();

        $this->clock->now = self::START + 620;
        self::assertSame(2, $this->store->deleteExpired(2));
        self::assertSame([], $this->storedRow($oldest));
        self::assertSame([], $this->storedRow($older));
        self::assertNotSame([], $this->storedRow($old));

        self::assertSame(1, $this->store->deleteExpired(2));
        self::assertSame(0, $this->store->deleteExpired(2));
        self::assertSame([], $this->storedRow($old));
        self::assertNotSame([], $this->storedRow($fresh));
    }

    public function testABatchBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->deleteExpired(0);
    }

    /**
     * The stored row of a token, or [] when there is none
     *
     * @param string $token
     * @return array<string, mixed>
     */
    private function storedRow(string $token): array
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName(DbConfirmationStore::TABLE))
                ->where('token = ?', $token)
        );

        if (!is_array($row)) {
            return [];
        }
        $columns = [];
        foreach ($row as $column => $value) {
            $columns[(string) $column] = $value;
        }

        return $columns;
    }

    /**
     * @param int $id
     * @return CallerInterface
     */
    private function admin(int $id): CallerInterface
    {
        return $this->service(CallerInterfaceFactory::class)
            ->create(['userType' => UserContextInterface::USER_TYPE_ADMIN, 'userId' => $id]);
    }

    /**
     * A service from the container, of the class asked for
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
