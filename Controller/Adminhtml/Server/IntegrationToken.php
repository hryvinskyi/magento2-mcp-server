<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Model\Admin\Http\NoStoreJsonInterface;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationNotReadyException;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationTokensInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Answers the access token of one integration an MCP client can use, for the MCP Server page to fill in
 *
 * Only a POST is served, and Magento's admin request validation checks its form key before the action runs. The
 * answer is JSON that no cache keeps: {"token": "…"} for a ready integration, or {"error": "…"} with 400 for an id
 * that is not a positive whole number, 404 for an integration that does not exist, 409 with the reason for one that
 * is not ready, and 500 for anything else, whose details go to the log only.
 *
 * Besides viewing the MCP Server page, the admin needs the right Magento itself requires to show integration tokens
 * on its own integration page; whoever may not see tokens there gets none here either.
 */
class IntegrationToken extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_McpServer::view';
    public const INTEGRATIONS_RESOURCE = 'Magento_Integration::integrations';

    /**
     * @param Context $context
     * @param IntegrationTokensInterface $tokens
     * @param LoggerInterface $logger
     * @param NoStoreJsonInterface $json
     */
    public function __construct(
        Context $context,
        private readonly IntegrationTokensInterface $tokens,
        private readonly LoggerInterface $logger,
        private readonly NoStoreJsonInterface $json
    ) {
        parent::__construct($context);
    }

    /**
     * Answer the token of the integration the request names, or why there is none
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $integrationId = $this->integrationId();
        if ($integrationId === null) {
            return $this->json->answer(400, ['error' => (string) __('Choose an integration.')]);
        }

        try {
            return $this->json->answer(200, ['token' => $this->tokens->tokenOf($integrationId)]);
        } catch (NoSuchEntityException) {
            return $this->json->answer(404, ['error' => (string) __('This integration does not exist any more.')]);
        } catch (IntegrationNotReadyException $exception) {
            return $this->json->answer(409, ['error' => $exception->getMessage()]);
        } catch (\Exception $exception) {
            $this->logger->error($exception);

            return $this->json->answer(
                500,
                ['error' => (string) __('The access token could not be read. The error is in the system log.')]
            );
        }
    }

    /**
     * Whether the admin may view the MCP Server page and see integration tokens
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE)
            && $this->_authorization->isAllowed(self::INTEGRATIONS_RESOURCE);
    }

    /**
     * The integration id the request names; null unless it is a positive whole number and nothing else
     *
     * The pattern ends at the very end of the value (`\z`): `$` alone would also accept a trailing line break.
     *
     * @return int|null
     */
    private function integrationId(): ?int
    {
        $value = $this->getRequest()->getParam('integration_id');
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
