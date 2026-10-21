<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Service;

use Magento\Framework\ObjectManagerInterface;

/**
 * Asks Magento's object manager for a service, as Magento's own REST processor does for a route's service
 *
 * This adapter is the one place in the module that names the object manager.
 */
class ObjectManagerServiceLocator implements ServiceLocatorInterface
{
    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $serviceInterface): object
    {
        if (!interface_exists($serviceInterface) && !class_exists($serviceInterface)) {
            throw new \UnexpectedValueException(sprintf('There is no service "%s".', $serviceInterface));
        }

        $service = $this->objectManager->get($serviceInterface);
        if (!is_object($service)) {
            throw new \UnexpectedValueException(sprintf('The service "%s" is not an object.', $serviceInterface));
        }

        return $service;
    }
}
