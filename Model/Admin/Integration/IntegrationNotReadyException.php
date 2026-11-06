<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Framework\Exception\LocalizedException;

/**
 * An integration's token was asked for while the integration is not ready; the message says why, for the admin
 *
 * Its own type, so that a caller tells this refusal from any other failure that reports a localized message.
 */
class IntegrationNotReadyException extends LocalizedException
{
}
