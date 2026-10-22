<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Ui\Component;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\MassAction;

/**
 * The catalog grid's mass actions, offered only to an admin who may manage the abilities' exposure
 */
class ManageMassAction extends MassAction
{
    public const MANAGE_RESOURCE = 'Hryvinskyi_McpServer::manage';

    /**
     * @param AuthorizationInterface $authorization
     * @param ContextInterface $context
     * @param array<string,UiComponentInterface> $components
     * @param array<mixed> $data
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        ContextInterface $context,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    /**
     * @inheritDoc
     */
    public function prepare()
    {
        if (!$this->authorization->isAllowed(self::MANAGE_RESOURCE)) {
            $this->components = [];
        }

        parent::prepare();
    }
}
