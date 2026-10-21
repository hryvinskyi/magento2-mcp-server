<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Where abilities come from: one kind of callable operation, listed, described, authorized and run
 *
 * A source answers only for the abilities it listed itself.
 *
 * @api
 */
interface AbilitySourceInterface
{
    /**
     * Code of the source, the value of AbilityInterface::getSource() on every ability it lists (`webapi`)
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Every ability the source offers
     *
     * @return iterable<AbilityInterface>
     */
    public function abilities(): iterable;

    /**
     * JSON Schema of the ability's arguments
     *
     * A JSON object that may be empty (`properties` of an ability without parameters, the schema `{}`) is an empty
     * \stdClass, so that it encodes as `{}` and never as `[]`.
     *
     * @param AbilityInterface $ability
     * @return array<string, mixed>
     */
    public function inputSchema(AbilityInterface $ability): array;

    /**
     * Whether the current caller's ACL lets it call the ability
     *
     * @param AbilityInterface $ability
     * @return bool
     */
    public function isAllowed(AbilityInterface $ability): bool;

    /**
     * Run the ability with these arguments and return what it returned
     *
     * @param AbilityInterface $ability
     * @param array<string,mixed> $arguments
     * @return mixed
     * @throws LocalizedException When the arguments do not suit the ability
     * @throws \Throwable When the operation itself fails
     */
    public function invoke(AbilityInterface $ability, array $arguments): mixed;
}
