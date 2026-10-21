<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Hryvinskyi\McpServer\Model\Service\ServiceLocatorInterface;

/**
 * A service locator that hands out one given service for every interface and remembers what it was asked for
 */
class RecordingServiceLocator implements ServiceLocatorInterface
{
    /**
     * Service interfaces asked for, in order
     *
     * @var list<string>
     */
    public array $asked = [];

    /**
     * @param object $service
     */
    public function __construct(
        private readonly object $service
    ) {
    }

    /**
     * @inheritDoc
     */
    public function get(string $serviceInterface): object
    {
        $this->asked[] = $serviceInterface;

        return $this->service;
    }
}
