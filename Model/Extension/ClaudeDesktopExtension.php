<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Extension;

use Hryvinskyi\McpServer\Model\Admin\ClientConnectionInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Module\Dir\Reader;

/**
 * The Claude Desktop extension (`.mcpb`) for this store: its manifest first, then the bridge the manifest starts
 *
 * The bridge files are read from the module's own directory, so the download always holds the bridge this module
 * ships. A refusal names a file by its path inside the module, never by where the module sits on the server.
 */
class ClaudeDesktopExtension implements ExtensionPackageInterface
{
    private const MODULE_NAME = 'Hryvinskyi_McpServer';
    private const MANIFEST_PATH = 'manifest.json';
    private const FILE_NAME_PREFIX = 'magento-mcp-';
    private const FILE_NAME_SUFFIX = '.mcpb';

    /**
     * The manifest's encoding: readable, URLs and non-Latin hosts written as they are
     */
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR;

    /**
     * @param ClientConnectionInterface $connection
     * @param ExtensionManifestInterface $manifest
     * @param ArchiveWriterInterface $archiveWriter
     * @param Reader $moduleReader
     * @param DriverInterface $driver
     * @param array<string,string> $files Path inside the extension => path inside the module
     */
    public function __construct(
        private readonly ClientConnectionInterface $connection,
        private readonly ExtensionManifestInterface $manifest,
        private readonly ArchiveWriterInterface $archiveWriter,
        private readonly Reader $moduleReader,
        private readonly DriverInterface $driver,
        private readonly array $files = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fileName(): string
    {
        return self::FILE_NAME_PREFIX . $this->manifest->slug($this->connection->endpointUrl())
            . self::FILE_NAME_SUFFIX;
    }

    /**
     * @inheritDoc
     */
    public function content(): string
    {
        $entries = [self::MANIFEST_PATH => $this->manifestJson()];
        $moduleDir = rtrim($this->moduleReader->getModuleDir('', self::MODULE_NAME), '/');
        foreach ($this->files as $entry => $modulePath) {
            $entries[$entry] = $this->read($moduleDir, $modulePath);
        }

        return $this->archiveWriter->archive($entries);
    }

    /**
     * The manifest for the store's endpoint, as JSON
     *
     * @return string
     * @throws LocalizedException
     */
    private function manifestJson(): string
    {
        $manifest = $this->manifest->build($this->connection->endpointUrl());
        try {
            return json_encode($manifest, self::JSON_FLAGS);
        } catch (\JsonException $exception) {
            throw new LocalizedException(__('The extension\'s manifest could not be written as JSON.'), $exception);
        }
    }

    /**
     * The content of a file of the module
     *
     * @param string $moduleDir
     * @param string $modulePath
     * @return string
     * @throws LocalizedException When the file is missing or cannot be read; the message names the module path only
     */
    private function read(string $moduleDir, string $modulePath): string
    {
        $path = $moduleDir . '/' . ltrim($modulePath, '/');
        try {
            if (!$this->driver->isExists($path)) {
                throw new LocalizedException(
                    __('The extension cannot be built: the file %1 is missing from the MCP Server module.', $modulePath)
                );
            }

            return $this->driver->fileGetContents($path);
        } catch (FileSystemException $exception) {
            throw new LocalizedException(
                __('The extension cannot be built: the file %1 of the MCP Server module cannot be read.', $modulePath),
                $exception
            );
        }
    }
}
