<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\PoolAbilityInterface;

/**
 * A registered ability with fixed values, which records every call and answers with the arguments it was given
 *
 * With $refusal set, every getter but getName() throws \InvalidArgumentException with that message, as an item that
 * finds its own values invalid does.
 */
class FakePoolAbility implements PoolAbilityInterface
{
    /**
     * Every call's arguments, in order
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * How many times the name was read
     *
     * @var int
     */
    public int $nameReads = 0;

    /**
     * @param string $name
     * @param list<string> $aclResources
     * @param AbilityAccess $access
     * @param array<string, mixed> $inputSchema
     * @param string $category
     * @param string $title
     * @param string|null $refusal
     */
    public function __construct(
        private readonly string $name,
        private readonly array $aclResources = ['Magento_Catalog::products'],
        private readonly AbilityAccess $access = AbilityAccess::Read,
        private readonly array $inputSchema = ['type' => 'object'],
        private readonly string $category = 'Acme_Shipping',
        private readonly string $title = 'Quote a shipment',
        private readonly ?string $refusal = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $this->nameReads++;

        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        $this->refuseWhenAsked();

        return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $this->refuseWhenAsked();

        return 'Quotes a shipment for a destination.';
    }

    /**
     * @inheritDoc
     */
    public function getCategory(): string
    {
        $this->refuseWhenAsked();

        return $this->category;
    }

    /**
     * @inheritDoc
     */
    public function getAccess(): AbilityAccess
    {
        $this->refuseWhenAsked();

        return $this->access;
    }

    /**
     * @inheritDoc
     */
    public function getAclResources(): array
    {
        $this->refuseWhenAsked();

        return $this->aclResources;
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): mixed
    {
        $this->calls[] = $arguments;

        return ['asked' => $arguments];
    }

    /**
     * Throw the refusal, when there is one
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    private function refuseWhenAsked(): void
    {
        if ($this->refusal !== null) {
            throw new \InvalidArgumentException($this->refusal);
        }
    }
}
