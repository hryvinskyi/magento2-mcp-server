<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Controller\Adminhtml\Section;

use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\Admin\CatalogSections;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Switches one section of the catalog ("section", its code) and returns to the MCP Server page
 *
 * A code no listed section has changes nothing.
 */
abstract class SectionSwitch extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_McpServer::manage';

    /**
     * @param Context $context
     * @param CatalogSections $sections
     * @param SectionSwitchesInterface $switches
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly CatalogSections $sections,
        protected readonly SectionSwitchesInterface $switches,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('hryvinskyi_mcp/server/index');
        $code = $this->getRequest()->getParam('section');
        $section = is_string($code) && $code !== '' ? $this->sections->find($code) : null;
        if ($section === null) {
            $this->messageManager->addErrorMessage(__('Choose a section.'));

            return $redirect;
        }

        try {
            $this->switchSection($section['code']);
            $this->messageManager->addSuccessMessage($this->doneMessage($section['label']));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Exception $exception) {
            $this->logger->error($exception);
            $this->messageManager->addErrorMessage(__('The section was not switched. The error is in the system log.'));
        }

        return $redirect;
    }

    /**
     * Switch the section
     *
     * @param string $code
     * @return void
     * @throws LocalizedException
     */
    abstract protected function switchSection(string $code): void;

    /**
     * What the merchant reads once the section is switched
     *
     * @param string $label
     * @return Phrase
     */
    abstract protected function doneMessage(string $label): Phrase;
}
