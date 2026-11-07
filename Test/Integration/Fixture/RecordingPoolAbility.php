<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\PoolAbilityInterface;

/**
 * A test ability the container registers in the pool source, which records every call and answers with the value it
 * was given to answer, or with the arguments of the call when it was given none
 *
 * It lets a test run, confirm or deny a call without depending on any store module: its name is in this module's
 * test vocabulary and its ACL resources are the ones the test names, from modules this one declares. Its values are
 * constructor arguments, so the same class serves every test ability RegistersTestAbilities registers.
 */
class RecordingPoolAbility implements PoolAbilityInterface
{
    /**
     * Name of the test ability that writes, which RegistersTestAbilities::registerTestSaveAbility() registers
     */
    public const SAVE = 'hryvinskyi_mcp_test.fixture.save';

    /**
     * Name of the test ability that reads and that anyone may call: its only ACL resource is `anonymous`
     */
    public const ANONYMOUS_READ = 'hryvinskyi_mcp_test.fixture.anonymous_read';

    /**
     * Every call's arguments, in order
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * @param string $name
     * @param string $title
     * @param string $access An AbilityAccess value: read, write or delete
     * @param list<string> $aclResources
     * @param list<array<string, string>>|null $answer What every call returns; null answers with the call's
     *     arguments under `saved`
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly string $access,
        private readonly array $aclResources,
        private readonly ?array $answer = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Records the call for the test that registered it.';
    }

    /**
     * @inheritDoc
     */
    public function getCategory(): string
    {
        return 'Hryvinskyi_McpServer';
    }

    /**
     * @inheritDoc
     */
    public function getAccess(): AbilityAccess
    {
        return AbilityAccess::from($this->access);
    }

    /**
     * @inheritDoc
     */
    public function getAclResources(): array
    {
        return $this->aclResources;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'record' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): mixed
    {
        $this->calls[] = $arguments;

        return $this->answer ?? ['saved' => $arguments];
    }
}
