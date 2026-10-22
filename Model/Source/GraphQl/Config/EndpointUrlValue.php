<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Source\GraphQl\Config;

use Hryvinskyi\McpServer\Model\Source\GraphQl\Client\EndpointUrlValidator;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Saves the GraphQL endpoint URL setting only when it is empty or an absolute http(s) URL ending in `/graphql`
 *
 * The value is stored trimmed. Empty means the chosen store view's own endpoint.
 */
class EndpointUrlValue extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param EndpointUrlValidator $validator
     * @param AbstractResource|null $resource
     * @param AbstractDb<\Magento\Framework\DataObject>|null $resourceCollection
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly EndpointUrlValidator $validator,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Refuse a URL the server must not call
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $url = trim((string) $this->getValue());
        if ($url !== '' && !$this->validator->isValid($url)) {
            throw new LocalizedException(
                __(
                    'The GraphQL endpoint URL must be an absolute http or https URL whose path ends in /graphql, '
                    . 'such as %1, with no user name, query string or fragment.',
                    'https://example.com/graphql'
                )
            );
        }
        $this->setValue($url);

        return parent::beforeSave();
    }
}
