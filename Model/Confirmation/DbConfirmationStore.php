<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Confirmation;

use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Model\Clock\WallClockInterface;
use Hryvinskyi\McpServer\Model\Db\ColumnValues;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Math\Random;
use Psr\Log\LoggerInterface;

/**
 * Keeps confirmation tokens as rows of the confirmation table
 *
 * A token is drawn from a cryptographically secure source. Spending one deletes its row first, and only the request
 * whose delete removed the row may use it, so two requests racing with the same token cannot both be confirmed.
 * Times are stored as UTC date-times (see ColumnValues). A row that no longer makes a valid token (it was
 * changed by hand) is logged and confirms nothing.
 */
class DbConfirmationStore implements ConfirmationStoreInterface
{
    public const TABLE = 'hryvinskyi_mcp_confirmation';

    private const TOKEN_LENGTH = 32;
    private const TOKEN_CHARS = '0123456789abcdef';

    /**
     * @param ResourceConnection $resource
     * @param Random $random
     * @param WallClockInterface $clock
     * @param ArgumentsFingerprint $fingerprint
     * @param ConfirmationFactory $confirmationFactory
     * @param CallerInterfaceFactory $callerFactory
     * @param ColumnValues $columns
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Random $random,
        private readonly WallClockInterface $clock,
        private readonly ArgumentsFingerprint $fingerprint,
        private readonly ConfirmationFactory $confirmationFactory,
        private readonly CallerInterfaceFactory $callerFactory,
        private readonly ColumnValues $columns,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function issue(string $sessionId, CallerInterface $caller, string $ability, array $arguments): Confirmation
    {
        $confirmation = $this->confirmationFactory->create([
            'token' => $this->random->getRandomString(self::TOKEN_LENGTH, self::TOKEN_CHARS),
            'sessionId' => $sessionId,
            'caller' => $caller,
            'ability' => $ability,
            'argumentsFingerprint' => $this->fingerprint->of($arguments),
            'expiresAt' => $this->clock->now() + Confirmation::LIFETIME_SECONDS,
        ]);

        $this->connection()->insert($this->table(), [
            'token' => $confirmation->getToken(),
            'session_id' => $sessionId,
            'user_type' => $caller->getUserType(),
            'user_id' => $caller->getUserId(),
            'ability' => $ability,
            'arguments_hash' => $confirmation->getArgumentsFingerprint(),
            'expires_at' => $this->columns->dateTime($confirmation->getExpiresAt()),
        ]);

        return $confirmation;
    }

    /**
     * @inheritDoc
     */
    public function consume(
        string $token,
        string $sessionId,
        CallerInterface $caller,
        string $ability,
        array $arguments
    ): bool {
        if (preg_match(Confirmation::TOKEN_PATTERN, $token) !== 1) {
            return false;
        }

        $connection = $this->connection();
        $row = $connection->fetchRow($connection->select()->from($this->table())->where('token = ?', $token));
        if (!is_array($row) || $connection->delete($this->table(), ['token = ?' => $token]) !== 1) {
            return false;
        }

        $confirmation = $this->confirmationOf($row);

        return $confirmation !== null && $confirmation->confirms(
            $sessionId,
            $caller,
            $ability,
            $this->fingerprint->of($arguments),
            $this->clock->now()
        );
    }

    /**
     * @inheritDoc
     */
    public function deleteExpired(int $batch): int
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('Expired confirmation tokens are deleted in batches of at least 1.');
        }

        $connection = $this->connection();
        $tokens = $connection->fetchCol(
            $connection->select()
                ->from($this->table(), ['token'])
                ->where('expires_at <= ?', $this->columns->dateTime($this->clock->now()))
                ->order('expires_at ASC')
                ->limit($batch)
        );
        if ($tokens === []) {
            return 0;
        }

        return $connection->delete($this->table(), ['token IN (?)' => $tokens]);
    }

    /**
     * The token a row holds, or null when the row no longer makes a valid one
     *
     * @param array<mixed> $row
     * @return Confirmation|null
     */
    private function confirmationOf(array $row): ?Confirmation
    {
        try {
            return $this->confirmationFactory->create([
                'token' => $this->columns->text($row, 'token'),
                'sessionId' => $this->columns->text($row, 'session_id'),
                'caller' => $this->callerFactory->create([
                    'userType' => (int) $this->columns->text($row, 'user_type'),
                    'userId' => $this->columns->nullableInt($row, 'user_id'),
                ]),
                'ability' => $this->columns->text($row, 'ability'),
                'argumentsFingerprint' => $this->columns->text($row, 'arguments_hash'),
                'expiresAt' => $this->columns->timestamp($row, 'expires_at'),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning(sprintf(
                'MCP confirmation row "%s" is not a valid confirmation token: %s',
                $this->columns->text($row, 'token'),
                $exception->getMessage()
            ));

            return null;
        }
    }

    /**
     * The connection to the confirmation table
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * The confirmation table's name, with the installation's table prefix
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
