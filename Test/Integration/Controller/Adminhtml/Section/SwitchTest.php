<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Section;

use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\ForgetsStoredNames;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Switching a section of the catalog off and on from the MCP Server page
 *
 * Each test dispatches once: the test application serves a second dispatch of one test from the first one's state.
 */
#[DbIsolation(true)]
class SwitchTest extends AbstractBackendController
{
    use ForgetsStoredNames;
    use SwitchesGraphQlSourceOff;

    private const SYSTEM = 'Magento_Backend::system';

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::manage';

    /**
     * @var string
     */
    protected $uri = 'backend/mcpserver/section/off';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    public function testASectionIsSwitchedOff(): void
    {
        $this->post('off', self::SYSTEM);

        self::assertSame([self::SYSTEM], $this->switches()->off());
        $this->assertSessionMessages(
            self::equalTo(['The section System is switched off: no client may call its abilities.']),
            MessageInterface::TYPE_SUCCESS
        );
        $this->assertRedirect(self::stringContains('mcpserver/server/index'));
    }

    public function testASectionSwitchedOffIsSwitchedOnAgain(): void
    {
        $this->switches()->switchOff(self::SYSTEM);

        $this->post('on', self::SYSTEM);

        self::assertSame([], $this->switches()->off());
        $this->assertSessionMessages(
            self::equalTo(['The section System is switched on.']),
            MessageInterface::TYPE_SUCCESS
        );
    }

    public function testACodeNoSectionHasChangesNothing(): void
    {
        $this->post('off', 'Vendor_Gone::area');

        self::assertSame([], $this->switches()->off());
        $this->assertSessionMessages(self::equalTo(['Choose a section.']), MessageInterface::TYPE_ERROR);
    }

    /**
     * Post the section to its switch
     *
     * @param string $switch "off" or "on"
     * @param string $section
     * @return void
     */
    private function post(string $switch, string $section): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setMethod(HttpRequest::METHOD_POST)->setPostValue(['section' => $section]);

        $this->dispatch('backend/mcpserver/section/' . $switch);
    }

    /**
     * @return SectionSwitchesInterface
     */
    private function switches(): SectionSwitchesInterface
    {
        $switches = Bootstrap::getObjectManager()->get(SectionSwitchesInterface::class);
        self::assertInstanceOf(SectionSwitchesInterface::class, $switches);

        return $switches;
    }
}
