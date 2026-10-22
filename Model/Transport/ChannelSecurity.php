<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Transport;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;

/**
 * The channel of the current message: secure once the transport marked it a local pipe, otherwise as secure as the
 * current HTTP request
 *
 * A command line has an HTTP request object too (Magento resolves the request to its HTTP class everywhere), which
 * reports plain HTTP; that is why the stdio transport marks its pipe instead of relying on the request. A current
 * request that is not an HTTP one carries nothing over the network and counts as secure.
 */
class ChannelSecurity implements ChannelSecurityInterface
{
    /**
     * @var bool
     */
    private bool $localChannel = false;

    /**
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly RequestInterface $request
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isSecure(): bool
    {
        if ($this->localChannel) {
            return true;
        }

        if (!$this->request instanceof HttpRequest) {
            return true;
        }

        return $this->request->isSecure();
    }

    /**
     * @inheritDoc
     */
    public function markLocalChannel(): void
    {
        $this->localChannel = true;
    }
}
