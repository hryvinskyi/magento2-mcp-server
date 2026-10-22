<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * An ability a module registers in di.xml, as an item of the `abilities` array of the pool source
 *
 * The item's key in that array must be the ability's name. The pool source lists the item as an ability of source
 * `pool`, and an item that breaks one of these rules is skipped with a warning while every other ability stays
 * listed:
 * - The name follows the ability-name format, AbilityInterface::NAME_PATTERN: `<module>.<service>.<method>`, each part
 *   lowercase words of letters and digits joined by `_`, at most AbilityInterface::NAME_MAX_LENGTH characters.
 * - The title and the category are not blank.
 * - getAclResources() names at least one ACL resource, and none of them is blank. A caller needs every one of them.
 *   An empty list would let every caller through, so an item that gives one is skipped.
 * - A getter that finds its own values invalid may throw \InvalidArgumentException; the item is then skipped.
 *
 * A registered ability wins over an ability of the same name from the Web API source.
 *
 * @api
 */
interface PoolAbilityInterface
{
    /**
     * Unique name, `<module>.<service>.<method>` (AbilityInterface::NAME_PATTERN), the item's key in di.xml
     *
     * @return string
     */
    public function getName(): string;

    /**
     * One-line title for people and models, not blank
     *
     * @return string
     */
    public function getTitle(): string;

    /**
     * Longer description of what the ability does, possibly empty
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Group the ability is listed under, not blank (the module that registers it, `Acme_Shipping`)
     *
     * @return string
     */
    public function getCategory(): string;

    /**
     * What calling the ability does to the store's data; read-only mode blocks every access but read
     *
     * @return AbilityAccess
     */
    public function getAccess(): AbilityAccess;

    /**
     * ACL resources a caller needs, every one of them, to call the ability; at least one
     *
     * @return list<string>
     */
    public function getAclResources(): array;

    /**
     * JSON Schema of the ability's arguments
     *
     * An empty array where the schema expects a JSON object (`properties`, a sub-schema) is given to clients as `{}`,
     * and an empty schema as an object schema without properties.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Run the ability and return its answer
     *
     * The arguments are the client's, as a plain array decoded from JSON, and are as untrusted as any request body.
     * Never hand them, or any array built from them, to a generated factory's create() or to the object manager's
     * create(): Magento reads such arrays as constructor arguments, so in developer mode a nested `instance` key there
     * builds an object of the client's choice and an `argument` key reads a value from `app/etc/env.php`.
     *
     * The answer must encode as JSON: null, scalars, arrays of them, or \JsonSerializable objects.
     *
     * @param array<string,mixed> $arguments
     * @return mixed
     * @throws LocalizedException With a message the client is shown, when the call cannot be done
     * @throws \Throwable When the operation itself fails; the client sees a masked message
     */
    public function execute(array $arguments): mixed;
}
