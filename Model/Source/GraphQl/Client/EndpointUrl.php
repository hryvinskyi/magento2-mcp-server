<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Client;

use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The GraphQL endpoint from the settings: the endpoint URL setting, else the chosen store view's own `/graphql`
 *
 * - The store view is the one the store code setting names, else the default store view. A code that names no active
 *   store view (unknown, inactive, or the admin store) falls back to the default store view, with a logged warning.
 * - The default URL is the store view's secure base URL of type web followed by `graphql`. The web URL carries no
 *   store code and no `index.php`, unlike the link URL: with store codes in URLs the link URL would end in
 *   `/default/`, and `/default/graphql` is not the GraphQL route.
 * - The default URL is refused when the stored secure base URL is a placeholder, or names the unsecure base URL and
 *   that one is: Magento fills a placeholder in from the host of the request it is serving and keeps the result, so
 *   a request with a forged host could send every GraphQL call to another server. A base URL that nothing stores
 *   comes from the shipped defaults, which are placeholders, and is refused too.
 * - Every URL, configured or derived, is used only when it is an absolute http(s) URL ending in `/graphql`, as the
 *   setting's backend model demands. A configured value written past it (`env.php`, a direct database write) is
 *   refused here as well.
 *
 * Every refusal is a transport failure: no GraphQL ability is offered, and the reason is logged by whoever asked.
 */
class EndpointUrl implements EndpointInterface
{
    private const GRAPHQL_PATH = 'graphql';
    private const PLACEHOLDER_START = '{{';
    private const UNSECURE_BASE_URL_PLACEHOLDER = '{{unsecure_base_url}}';

    /**
     * @param GraphQlConfigInterface $config
     * @param StoreManagerInterface $storeManager
     * @param EndpointUrlValidator $validator
     * @param StoredConfigInterface $storedConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GraphQlConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly EndpointUrlValidator $validator,
        private readonly StoredConfigInterface $storedConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function url(): string
    {
        $configured = $this->config->getEndpointUrl();
        if ($configured !== '') {
            if (!$this->validator->isValid($configured)) {
                throw new GraphQlTransportException(
                    'The GraphQL endpoint URL setting is not an absolute http(s) URL ending in /graphql.'
                );
            }

            return $configured;
        }

        $store = $this->store();
        if (!$store instanceof Store) {
            throw new GraphQlTransportException(
                sprintf('The base URL of the store view "%s" cannot be read.', (string) $store->getCode())
            );
        }

        if ($this->followsTheRequest($store)) {
            throw new GraphQlTransportException(sprintf(
                'The secure base URL of the store view "%s" is a placeholder that Magento fills in from the host of '
                . 'each request; set the GraphQL Endpoint URL setting.',
                (string) $store->getCode()
            ));
        }

        $url = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/') . '/' . self::GRAPHQL_PATH;
        if (!$this->validator->isValid($url)) {
            throw new GraphQlTransportException(sprintf(
                'The secure base URL of the store view "%s" does not give an absolute http(s) URL ending in '
                . '/graphql; set the GraphQL Endpoint URL setting.',
                (string) $store->getCode()
            ));
        }

        return $url;
    }

    /**
     * @inheritDoc
     */
    public function storeCode(): string
    {
        return (string) $this->store()->getCode();
    }

    /**
     * Whether the store view's secure base URL, as stored, holds a placeholder Magento fills in per request
     *
     * A secure base URL that names the unsecure one is as fixed as the unsecure one is stored.
     *
     * @param Store $store
     * @return bool
     * @throws GraphQlTransportException When the stored base URL cannot be read
     */
    private function followsTheRequest(Store $store): bool
    {
        try {
            $secure = $this->storedConfig->storeViewValue(Store::XML_PATH_SECURE_BASE_URL, $store);
            $unsecure = str_contains($secure, self::UNSECURE_BASE_URL_PLACEHOLDER)
                ? $this->storedConfig->storeViewValue(Store::XML_PATH_UNSECURE_BASE_URL, $store)
                : null;
        } catch (LocalizedException $exception) {
            throw new GraphQlTransportException(sprintf(
                'The stored base URL of the store view "%s" cannot be read: %s',
                (string) $store->getCode(),
                $exception->getMessage()
            ));
        }

        if ($secure === '' || $unsecure === '' || str_contains((string) $unsecure, self::PLACEHOLDER_START)) {
            return true;
        }

        return str_contains(
            str_replace(self::UNSECURE_BASE_URL_PLACEHOLDER, '', $secure),
            self::PLACEHOLDER_START
        );
    }

    /**
     * The store view the setting names, else the default store view
     *
     * @return StoreInterface
     * @throws GraphQlTransportException
     */
    private function store(): StoreInterface
    {
        $code = $this->config->getStoreCode();
        if ($code !== '') {
            $store = $this->activeStoreView($code);
            if ($store !== null) {
                return $store;
            }
            $this->logger->warning(sprintf(
                'MCP runs GraphQL abilities in the default store view: the store view "%s" chosen for them is '
                . 'unknown or inactive.',
                $code
            ));
        }

        $default = $this->storeManager->getDefaultStoreView();
        if ($default === null) {
            throw new GraphQlTransportException('The installation has no default store view.');
        }

        return $default;
    }

    /**
     * The active store view with exactly this code, or null
     *
     * The store manager also takes a numeric id, so the code of what it finds is compared with the one asked for.
     *
     * @param string $code
     * @return StoreInterface|null
     */
    private function activeStoreView(string $code): ?StoreInterface
    {
        try {
            $store = $this->storeManager->getStore($code);
        } catch (NoSuchEntityException) {
            return null;
        }

        $isStoreView = (int) $store->getId() !== Store::DEFAULT_STORE_ID;

        return $isStoreView && $store->getCode() === $code && (bool) $store->getIsActive() ? $store : null;
    }
}
