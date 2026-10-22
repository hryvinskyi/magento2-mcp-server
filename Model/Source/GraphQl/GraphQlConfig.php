<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reads the GraphQL settings from the default scope, like every other setting of the server
 */
class GraphQlConfig implements GraphQlConfigInterface
{
    public const XML_PATH_ENABLED = 'hryvinskyi_mcp_server/graphql/enabled';
    public const XML_PATH_EXPOSE_MUTATIONS = 'hryvinskyi_mcp_server/graphql/expose_mutations';
    public const XML_PATH_ENDPOINT_URL = 'hryvinskyi_mcp_server/graphql/endpoint_url';
    public const XML_PATH_STORE_CODE = 'hryvinskyi_mcp_server/graphql/store_code';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * @inheritDoc
     */
    public function isMutationExposed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_EXPOSE_MUTATIONS);
    }

    /**
     * @inheritDoc
     */
    public function getEndpointUrl(): string
    {
        return $this->text(self::XML_PATH_ENDPOINT_URL);
    }

    /**
     * @inheritDoc
     */
    public function getStoreCode(): string
    {
        return $this->text(self::XML_PATH_STORE_CODE);
    }

    /**
     * A setting as trimmed text; a missing one is ''
     *
     * @param string $path
     * @return string
     */
    private function text(string $path): string
    {
        $value = $this->scopeConfig->getValue($path);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
