<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Extension;

/**
 * Packs files into a zip archive held in memory
 *
 * The archive is a function of its input alone: the same files in the same order always give the same bytes.
 */
interface ArchiveWriterInterface
{
    /**
     * The bytes of a zip archive holding the given files, in the given order
     *
     * @param array<string,string> $files Path inside the archive (forward slashes, relative) => file content
     * @return string
     * @throws \InvalidArgumentException When a path is empty, absolute, holds a backslash, or a "." or ".." segment
     */
    public function archive(array $files): string;
}
