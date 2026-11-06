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
use Hryvinskyi\McpServer\Model\Admin\Http\NoStoreJsonInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Switches one section of the catalog ("section", its code)
 *
 * A form post returns to the MCP Server page with a message. An AJAX post (the sections card's script) is answered
 * with JSON instead, never cached:
 * - 200 `{"message", "exposed", "sections": [{"code", "off", "exposed"}, …]}`, counted after the switch: `exposed` is
 *   how many abilities of the whole catalog a client may call (the page's "Exposed" fact), `sections` the state of
 *   every listed section (an ability can sit in more than one section, so one switch changes the counts of others);
 * - 400 `{"error"}` for a code no listed section has, which changes nothing;
 * - 500 `{"error"}` when the switch could not be stored: the sentence names the section, the exception goes to the
 *   log with the cause it carries.
 * The code is checked against the listed sections before anything is stored, so an exception from the switch is
 * always a failure to store, never a refusal; it is answered the same way whatever its type.
 *
 * @phpstan-type Outcome array{status: int, message: string}
 * @phpstan-import-type SectionRow from CatalogSections
 */
abstract class SectionSwitch extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Hryvinskyi_McpServer::manage';

    /**
     * @param Context $context
     * @param CatalogSections $sections
     * @param SectionSwitchesInterface $switches
     * @param LoggerInterface $logger
     * @param NoStoreJsonInterface $json
     */
    public function __construct(
        Context $context,
        private readonly CatalogSections $sections,
        protected readonly SectionSwitchesInterface $switches,
        private readonly LoggerInterface $logger,
        private readonly NoStoreJsonInterface $json
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $outcome = $this->switchRequested();
        $request = $this->getRequest();

        return $request instanceof HttpRequest && $request->isAjax()
            ? $this->answer($outcome)
            : $this->backToPage($outcome);
    }

    /**
     * Switch the section the request names, and say how it went
     *
     * @return Outcome
     */
    private function switchRequested(): array
    {
        $code = $this->getRequest()->getParam('section');
        $section = is_string($code) && $code !== '' ? $this->sections->find($code) : null;
        if ($section === null) {
            return ['status' => 400, 'message' => (string) __('Choose a section.')];
        }

        try {
            $this->switchSection($section['code']);

            return ['status' => 200, 'message' => (string) $this->doneMessage($section['label'])];
        } catch (\Exception $exception) {
            $this->logger->error($exception);

            return [
                'status' => 500,
                'message' => (string) __(
                    'The section %1 could not be switched. The error is in the system log.',
                    $section['label']
                ),
            ];
        }
    }

    /**
     * Back to the MCP Server page, with the outcome as its message
     *
     * @param Outcome $outcome
     * @return ResultInterface
     */
    private function backToPage(array $outcome): ResultInterface
    {
        if ($outcome['status'] === 200) {
            $this->messageManager->addSuccessMessage($outcome['message']);
        } else {
            $this->messageManager->addErrorMessage($outcome['message']);
        }

        return $this->resultRedirectFactory->create()->setPath('hryvinskyi_mcp/server/index');
    }

    /**
     * The outcome as JSON for the sections card's script
     *
     * @param Outcome $outcome
     * @return ResultInterface
     */
    private function answer(array $outcome): ResultInterface
    {
        if ($outcome['status'] !== 200) {
            return $this->json->answer($outcome['status'], ['error' => $outcome['message']]);
        }

        $recounted = $this->sections->recountedWithTotal();

        return $this->json->answer(200, [
            'message' => $outcome['message'],
            'exposed' => $recounted['exposed'],
            'sections' => $this->states($recounted['sections']),
        ]);
    }

    /**
     * The state of each section, as the sections card's script redraws it
     *
     * @param list<SectionRow> $sections
     * @return list<array{code: string, off: bool, exposed: int}>
     */
    private function states(array $sections): array
    {
        return array_map(
            static fn (array $section): array => [
                'code' => $section['code'],
                'off' => $section['off'],
                'exposed' => $section['exposed'],
            ],
            $sections
        );
    }

    /**
     * Switch the section
     *
     * @param string $code
     * @return void
     * @throws CouldNotSaveException
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
