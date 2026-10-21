<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Http;

use Hryvinskyi\McpServer\Api\ConfigInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Model\Http\Verb\VerbInterface;
use Hryvinskyi\McpServer\Model\Protocol\Answers;
use Hryvinskyi\McpServer\Model\Protocol\AnswerStatus;
use Hryvinskyi\McpServer\Model\Protocol\ErrorCode;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolAnswer;
use Hryvinskyi\McpServer\Model\Protocol\TransportContextFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Webapi\Controller\Rest\RequestProcessorInterface;
use Magento\Webapi\Controller\Rest\RequestValidator;
use Psr\Log\LoggerInterface;

/**
 * The MCP endpoint on Magento's REST stack: translates an HTTP request into the protocol's terms and the protocol's
 * answer back into HTTP, and decides nothing about the protocol itself
 *
 * First Magento's own REST request validator runs, the one Magento's synchronous processor runs for every other
 * route: it matches the route, authenticates the caller, checks the route's ACL resource, enforces HTTPS where the
 * route asks for it and applies backpressure. Magento's REST controller only checks the HTTP method before handing
 * the request to a processor, so a processor that skipped this step would answer anyone. A refusal it raises reaches
 * the REST controller untouched and is answered in Magento's own format (401 for a caller without access). Then:
 * - a disabled server refuses every request with 403;
 * - an HTTP method the endpoint does not serve is answered with 405 and an `Allow` header listing the ones it does;
 * - a store code other than `all` is refused with 400, because abilities would otherwise run in a store view's scope
 *   and, for instance, save store-level values;
 * - an `Origin` the installation does not serve is refused with 403 (see OriginPolicy);
 * - everything else goes to the HTTP method's verb with the caller and the raw `Mcp-Session-Id` and
 *   `MCP-Protocol-Version` header values.
 *
 * Nothing else escapes to Magento's REST controller, which would answer in its own error format rather than JSON-RPC:
 * any failure after the enabled check is logged and answered with a JSON-RPC Internal error. The answer is written to
 * the shared REST response as it is (status, headers, raw body), never through Magento's response renderer.
 */
class McpRequestProcessor implements RequestProcessorInterface
{
    public const PATH = '/V1/mcp';
    public const TRANSPORT = 'http';

    private const HEADER_SESSION_ID = 'Mcp-Session-Id';
    private const HEADER_PROTOCOL_VERSION = 'MCP-Protocol-Version';
    private const HEADER_ORIGIN = 'Origin';
    private const HTTP_METHOD_NOT_ALLOWED = 405;

    /**
     * @param RequestValidator $requestValidator
     * @param ConfigInterface $config
     * @param StoreManagerInterface $storeManager
     * @param OriginPolicy $originPolicy
     * @param UserContextInterface $userContext
     * @param CallerInterfaceFactory $callerFactory
     * @param TransportContextFactory $transportContextFactory
     * @param Answers $answers
     * @param Response $response
     * @param LoggerInterface $logger
     * @param array<string,VerbInterface> $verbs What the endpoint does per uppercase HTTP method
     */
    public function __construct(
        private readonly RequestValidator $requestValidator,
        private readonly ConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly OriginPolicy $originPolicy,
        private readonly UserContextInterface $userContext,
        private readonly CallerInterfaceFactory $callerFactory,
        private readonly TransportContextFactory $transportContextFactory,
        private readonly Answers $answers,
        private readonly Response $response,
        private readonly LoggerInterface $logger,
        private readonly array $verbs = []
    ) {
    }

    /**
     * Whether the request is for the MCP endpoint: its path exactly, whatever the HTTP method
     *
     * Routes under the endpoint's path (`/V1/mcp/…`) belong to other services and are never taken.
     *
     * @param Request $request
     * @return bool
     */
    public function canProcess(Request $request)
    {
        return rtrim($request->getPathInfo(), '/') === self::PATH;
    }

    /**
     * Answer the request on the shared REST response, once Magento has let the caller through
     *
     * @param Request $request
     * @return void
     * @throws AuthorizationException When the caller may not use the endpoint, answered by Magento
     * @throws WebapiException When Magento refuses the request itself, such as over plain HTTP or too often
     */
    public function process(Request $request)
    {
        $this->requestValidator->validate();

        if (!$this->config->isEnabled()) {
            $this->write($this->answers->error(
                AnswerStatus::Forbidden,
                ErrorCode::ServerDisabled,
                __('The MCP server is disabled.')->render()
            ));

            return;
        }

        try {
            $this->answer($request);
        } catch (\Throwable $failure) {
            $this->logger->error(
                sprintf('The MCP endpoint failed: %s', $failure->getMessage()),
                ['exception' => $failure]
            );
            $this->write(
                $this->answers->error(AnswerStatus::Ok, ErrorCode::InternalError, __('Internal error')->render())
            );
        }
    }

    /**
     * Write the endpoint's answer to the request
     *
     * @param Request $request
     * @return void
     */
    private function answer(Request $request): void
    {
        $verb = $this->verbs[strtoupper($request->getMethod())] ?? null;
        if ($verb === null) {
            $this->writeMethodNotAllowed();

            return;
        }

        if ($this->storeManager->getStore()->getCode() !== Store::ADMIN_CODE) {
            $this->write($this->answers->error(
                AnswerStatus::BadRequest,
                ErrorCode::InvalidRequest,
                __('Use the endpoint with store code all: /rest/all/V1/mcp.')->render()
            ));

            return;
        }

        if (!$this->originPolicy->allows($this->header($request, self::HEADER_ORIGIN))) {
            $this->write($this->answers->error(
                AnswerStatus::Forbidden,
                ErrorCode::InvalidRequest,
                __('Forbidden: the Origin header names a host this store does not serve.')->render()
            ));

            return;
        }

        $this->write($verb->answer($request, $this->transportContextFactory->create([
            'caller' => $this->callerFactory->create([
                'userType' => (int) $this->userContext->getUserType(),
                'userId' => $this->userContext->getUserId(),
            ]),
            'transport' => self::TRANSPORT,
            'sessionId' => $this->header($request, self::HEADER_SESSION_ID),
            'protocolVersion' => $this->header($request, self::HEADER_PROTOCOL_VERSION),
        ])));
    }

    /**
     * Put the protocol's answer on the response: status, JSON body and the session it opened
     *
     * @param ProtocolAnswer $answer
     * @return void
     */
    private function write(ProtocolAnswer $answer): void
    {
        $this->response->setHttpResponseCode($this->httpStatusOf($answer->getStatus()));
        $this->response->setBody($answer->getBody());
        if ($answer->getBody() !== '') {
            $this->response->setHeader('Content-Type', 'application/json', true);
        }
        if ($answer->getSessionId() !== '') {
            $this->response->setHeader(self::HEADER_SESSION_ID, $answer->getSessionId(), true);
        }
    }

    /**
     * Refuse an HTTP method the endpoint does not serve, naming the ones it does
     *
     * @return void
     */
    private function writeMethodNotAllowed(): void
    {
        $this->response->setHttpResponseCode(self::HTTP_METHOD_NOT_ALLOWED);
        $this->response->setBody('');
        $this->response->setHeader('Allow', implode(', ', array_keys($this->verbs)), true);
    }

    /**
     * The HTTP status code of a protocol answer
     *
     * @param AnswerStatus $status
     * @return int
     */
    private function httpStatusOf(AnswerStatus $status): int
    {
        return match ($status) {
            AnswerStatus::Ok => 200,
            AnswerStatus::Accepted => 202,
            AnswerStatus::NoContent => 204,
            AnswerStatus::BadRequest => 400,
            AnswerStatus::NotFound => 404,
            AnswerStatus::Forbidden => 403,
        };
    }

    /**
     * A request header's value as the client sent it, or '' when it sent none
     *
     * @param Request $request
     * @param string $name
     * @return string
     */
    private function header(Request $request, string $name): string
    {
        $value = $request->getHeader($name);

        return is_string($value) ? $value : '';
    }
}
