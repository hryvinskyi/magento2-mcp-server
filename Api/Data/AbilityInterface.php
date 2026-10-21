<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Api\Data;

/**
 * One MCP-callable operation, as an immutable value
 *
 * An ability is named `<module>.<service>.<method>`, each part lowercase words joined by `_`
 * (`catalog.product_repository.get_list`). The name is unique across every ability source, and an ability whose
 * name breaks that format cannot be built.
 *
 * The value carries no input schema: building one needs services, so it comes from the catalog
 * (AbilityCatalogInterface::schemaOf()).
 *
 * Create one through AbilityInterfaceFactory.
 *
 * @api
 */
interface AbilityInterface
{
    /**
     * The format every ability name follows
     */
    public const NAME_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*(?:\.[a-z0-9]+(?:_[a-z0-9]+)*){2}$/D';

    /**
     * The longest ability name, in characters
     */
    public const NAME_MAX_LENGTH = 255;

    /**
     * Unique name, `<module>.<service>.<method>`
     *
     * @return string
     */
    public function getName(): string;

    /**
     * One-line title for people and models
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
     * Group the ability belongs to: the Magento module that provides it (`Magento_Catalog`)
     *
     * @return string
     */
    public function getCategory(): string;

    /**
     * Code of the ability source the ability comes from (`webapi`)
     *
     * @return string
     */
    public function getSource(): string;

    /**
     * Where the source finds the ability, in the source's own terms (for a REST route, `GET /V1/products`)
     *
     * @return string
     */
    public function getSourceReference(): string;

    /**
     * What calling the ability does to the store's data
     *
     * @return AbilityAccess
     */
    public function getAccess(): AbilityAccess;

    /**
     * ACL resources a caller needs, every one of them, to call the ability
     *
     * @return list<string>
     */
    public function getAclResources(): array;
}
