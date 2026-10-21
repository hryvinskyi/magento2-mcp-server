<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Http\Verb;

use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\Server;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Magento\Framework\Webapi\Rest\Request;

/**
 * POST: the body holds JSON-RPC messages, handed to the server exactly as the client sent them
 */
class PostMessages implements VerbInterface
{
    /**
     * @param Server $server
     */
    public function __construct(
        private readonly Server $server
    ) {
    }

    /**
     * @inheritDoc
     */
    public function answer(Request $request, TransportContext $context): ProtocolAnswer
    {
        return $this->server->handle($request->getContent(), $context);
    }
}
