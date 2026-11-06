<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Http;

use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * A no-store JSON answer built on Magento's JSON controller result
 */
class NoStoreJson implements NoStoreJsonInterface
{
    /**
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        private readonly ResultFactory $resultFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function answer(int $status, array $body): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        if ($result instanceof Json) {
            $result->setData($body);
        }

        return $result->setHttpResponseCode($status)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setHeader('Pragma', 'no-cache', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true);
    }
}
