<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl;

/**
 * Settings of the GraphQL abilities, read at the default scope
 */
interface GraphQlConfigInterface
{
    /**
     * Whether GraphQL query fields are offered as abilities; on after install
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Whether GraphQL mutation fields are offered as abilities as well; off after install
     *
     * @return bool
     */
    public function isMutationExposed(): bool;

    /**
     * The GraphQL endpoint the server calls, trimmed; '' means the chosen store view's own `/graphql`
     *
     * @return string
     */
    public function getEndpointUrl(): string;

    /**
     * Code of the store view GraphQL abilities run in, trimmed; '' means the default store view
     *
     * @return string
     */
    public function getStoreCode(): string;
}
