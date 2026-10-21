<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Service;

/**
 * Finds the service an ability calls, by the service interface its source names at run time
 *
 * An ability source learns which service to call from configuration, so the service cannot be injected by type.
 */
interface ServiceLocatorInterface
{
    /**
     * The shared instance of the service behind the interface
     *
     * @param string $serviceInterface
     * @return object
     * @throws \UnexpectedValueException When the container has no service for the interface
     */
    public function get(string $serviceInterface): object;
}
