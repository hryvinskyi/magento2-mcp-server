<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\Webapi\Schema;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Model\Source\Webapi\Route;

/**
 * Builds the JSON Schema of a Web API ability's arguments
 *
 * The schema of an ability depends on its route's service method only, so the ability's name identifies it.
 */
interface InputSchemaBuilderInterface
{
    /**
     * JSON Schema (a draft 2020-12 subset) of the arguments the ability takes, marked when it is only a fallback
     *
     * A JSON object that may be empty (`properties` of a method without parameters, the schema `{}` that takes
     * anything) is an empty \stdClass, so that it encodes as `{}` and never as `[]`.
     *
     * @param AbilityInterface $ability
     * @param Route $route The route the ability calls
     * @return InputSchema
     */
    public function build(AbilityInterface $ability, Route $route): InputSchema;
}
