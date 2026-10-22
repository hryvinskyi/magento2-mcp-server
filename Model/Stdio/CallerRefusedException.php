<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Stdio;

use Magento\Framework\Exception\LocalizedException;

/**
 * The stdio transport will not serve this caller; the message says why, for the person running the command
 */
class CallerRefusedException extends LocalizedException
{
}
