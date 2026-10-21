<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Http\Verb;

use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\TransportContext;
use Magento\Framework\Webapi\Rest\Request;

/**
 * What the MCP endpoint does for one HTTP method
 *
 * The request processor holds these in a di.xml pool keyed by the uppercase HTTP method. A method without an entry is
 * answered with 405, and the pool's keys are what the answer's `Allow` header lists.
 */
interface VerbInterface
{
    /**
     * The protocol's answer to the request
     *
     * @param Request $request
     * @param TransportContext $context What the transport read from the request
     * @return ProtocolAnswer
     */
    public function answer(Request $request, TransportContext $context): ProtocolAnswer;
}
