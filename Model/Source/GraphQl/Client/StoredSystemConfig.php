<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

use Magento\Framework\App\Config\ConfigSourceInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stored settings read from the two places Magento stores them, before any of its processing
 *
 * - The deployment configuration (the `system` section of `app/etc/config.php` and `env.php`) wins over the database
 *   (`core_config_data`) in each scope, as it does when Magento merges them.
 * - Scopes are tried from the store view to its website to the default scope; the first that stores a value wins.
 * - The defaults modules ship in `config.xml` are not read: a value only they hold counts as not stored.
 *
 * Both sources are read anew on every call, so a setting saved in the meantime is seen.
 */
class StoredSystemConfig implements StoredConfigInterface
{
    private const SCOPE_STORES = 'stores';
    private const SCOPE_WEBSITES = 'websites';
    private const SCOPE_DEFAULT = 'default';

    /**
     * @param ConfigSourceInterface $deploymentConfig The `system` settings of the deployment configuration
     * @param ConfigSourceInterface $databaseConfig The settings stored in the database
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ConfigSourceInterface $deploymentConfig,
        private readonly ConfigSourceInterface $databaseConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function storeViewValue(string $path, StoreInterface $store): string
    {
        $websiteCode = (string) $this->storeManager->getWebsite((int) $store->getWebsiteId())->getCode();
        $sources = [$this->deploymentConfig->get(), $this->databaseConfig->get()];
        $scopes = [
            [self::SCOPE_STORES, (string) $store->getCode()],
            [self::SCOPE_WEBSITES, $websiteCode],
            [self::SCOPE_DEFAULT],
        ];

        foreach ($scopes as $scope) {
            $keys = [...$scope, ...explode('/', $path)];
            foreach ($sources as $stored) {
                $value = $this->valueAt($stored, $keys);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * The scalar value under these nested keys, as a string, or null when there is none
     *
     * @param mixed $stored
     * @param list<string> $keys
     * @return string|null
     */
    private function valueAt(mixed $stored, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!is_array($stored) || !array_key_exists($key, $stored)) {
                return null;
            }
            $stored = $stored[$key];
        }

        return is_scalar($stored) ? (string) $stored : null;
    }
}
