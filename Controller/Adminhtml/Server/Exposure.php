<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Model\Admin\ExposureChanges;
use Hryvinskyi\McpServer\Ui\MassAction\SelectedAbilities;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Puts abilities on the deny-list or takes them off, and asks for confirmation of their calls or stops asking
 *
 * The change ("change") applies to one ability ("name", a row's action) or to the grid's selection (a mass action).
 */
class Exposure extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_McpServer::manage';

    /**
     * @param Context $context
     * @param ExposureChanges $changes
     * @param SelectedAbilities $selectedAbilities
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ExposureChanges $changes,
        private readonly SelectedAbilities $selectedAbilities,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Apply the change and go back to the page
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        $change = $this->getRequest()->getParam('change');
        if (!is_string($change) || !$this->changes->exists($change)) {
            $this->messageManager->addErrorMessage(__('Choose what to change.'));

            return $redirect;
        }

        try {
            $applied = $this->changes->apply($change, $this->names());
            $this->messageManager->addSuccessMessage(__('A total of %1 abilities were changed.', $applied));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage(__('No ability was changed: %1', $exception->getMessage()));
        } catch (\Exception $exception) {
            $this->logger->error($exception);
            $this->messageManager->addErrorMessage(
                __('No ability was changed. The error is in the system log.')
            );
        }

        return $redirect;
    }

    /**
     * The abilities the change is for: the row's, or the grid's selection
     *
     * @return list<string>
     * @throws LocalizedException
     */
    private function names(): array
    {
        $name = $this->getRequest()->getParam('name');

        return is_string($name) && $name !== '' ? [$name] : $this->selectedAbilities->of($this->getRequest());
    }
}
