<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Exception;

/**
 * A meta-tool was called with an argument it cannot take
 *
 * It is answered as a tool result marked as an error, so the model reads the message and corrects the call.
 */
class InvalidToolArgumentException extends \InvalidArgumentException
{
}
