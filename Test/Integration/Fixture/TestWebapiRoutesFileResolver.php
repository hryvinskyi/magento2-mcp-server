<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Magento\Framework\Config\FileIteratorFactory;
use Magento\Framework\Config\FileResolverInterface;

/**
 * The modules' `webapi.xml` files, and the test routes of `webapi.xml` next to this class after them
 */
class TestWebapiRoutesFileResolver implements FileResolverInterface
{
    /**
     * @param FileResolverInterface $moduleFiles The resolver Magento reads the modules' configuration files with
     * @param FileIteratorFactory $fileIteratorFactory
     */
    public function __construct(
        private readonly FileResolverInterface $moduleFiles,
        private readonly FileIteratorFactory $fileIteratorFactory
    ) {
    }

    /**
     * The modules' files of that name, then the test routes, as file contents by path
     *
     * Magento's own resolver answers with an iterator of the files, so the files are read by walking it.
     *
     * @param string $filename
     * @param string $scope
     * @return array<string, string>
     */
    public function get($filename, $scope)
    {
        $files = [];
        foreach ($this->moduleFiles->get($filename, $scope) as $path => $content) {
            if (is_string($content)) {
                $files[(string) $path] = $content;
            }
        }
        $testRoutes = __DIR__ . '/webapi.xml';
        foreach ($this->fileIteratorFactory->create([$testRoutes]) as $content) {
            $files[$testRoutes] = $content;
        }

        return $files;
    }
}
