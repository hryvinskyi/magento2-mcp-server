<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Model\Extension\ExtensionPackageInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Downloads the Claude Desktop extension that connects Claude to this store's MCP endpoint
 *
 * The file is sent as it is built, never stored and never cached. When it cannot be built, the admin goes back to
 * Stores › MCP Server and reads why there.
 */
class Extension extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_McpServer::view';

    /**
     * @param Context $context
     * @param ExtensionPackageInterface $package
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ExtensionPackageInterface $package,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Send the extension as a file, or go back to the page and say why it cannot be built
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        try {
            $fileName = $this->package->fileName();
            $content = $this->package->content();
        } catch (LocalizedException $exception) {
            $this->logger->error($exception);
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $this->resultRedirectFactory->create()->setPath('hryvinskyi_mcp/server/index');
        }

        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        if ($result instanceof Raw) {
            $result->setHeader('Content-Type', 'application/zip', true)
                ->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setHeader('X-Content-Type-Options', 'nosniff', true)
                ->setContents($content);
        }

        return $result;
    }
}
