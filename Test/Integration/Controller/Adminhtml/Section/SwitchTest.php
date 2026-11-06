<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Section;

use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Api\SectionSwitchesInterface;
use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\Admin\CatalogSections;
use Hryvinskyi\McpServer\Test\Integration\Fake\FailingSectionSwitches;
use Hryvinskyi\McpServer\Test\Integration\Fixture\ForgetsStoredNames;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Psr\Log\LoggerInterface;

/**
 * Switching a section of the catalog off and on from the MCP Server page, by a form post or by the sections card's
 * script
 *
 * Each test dispatches once: the test application serves a second dispatch of one test from the first one's state.
 */
#[DbIsolation(true)]
class SwitchTest extends AbstractBackendController
{
    use ForgetsStoredNames;
    use SwitchesGraphQlSourceOff;

    private const SYSTEM = 'Magento_Backend::system';
    private const FAILED = 'The section System could not be switched. The error is in the system log.';

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

    public function testAnAjaxSwitchIsAnsweredWithEverySectionsStateCountedAfterTheSwitch(): void
    {
        $this->post('off', self::SYSTEM, true);

        self::assertSame([self::SYSTEM], $this->switches()->off());
        $response = $this->response();
        self::assertSame(200, $response->getHttpResponseCode());
        self::assertStringContainsString('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame('no-cache', $this->header($response, 'Pragma'));
        self::assertSame('nosniff', $this->header($response, 'X-Content-Type-Options'));
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(['message', 'exposed', 'sections'], array_keys($body));
        self::assertSame('The section System is switched off: no client may call its abilities.', $body['message']);
        self::assertIsInt($body['exposed']);
        self::assertSame($this->exposedTotal(), $body['exposed'], 'The total is the one after the switch');
        self::assertIsArray($body['sections']);
        $system = array_values(array_filter(
            $body['sections'],
            static fn (mixed $section): bool => is_array($section) && ($section['code'] ?? null) === self::SYSTEM
        ));
        self::assertSame([['code' => self::SYSTEM, 'off' => true, 'exposed' => 0]], $system);
        self::assertCount(count($this->sections()->all()), $body['sections'], 'Every section is in the answer');
        $this->assertSessionMessages(self::isEmpty());

        // The controller read the sections before it switched; on again, System exposes abilities, so the 0 above is
        // the count after the switch, not a section that never exposed anything, and the total grows by them.
        $this->switches()->switchOn(self::SYSTEM);
        self::assertGreaterThan(0, $this->exposedOf($this->sections()->recounted(), self::SYSTEM));
        self::assertGreaterThan($body['exposed'], $this->exposedTotal());
    }

    #[AppIsolation(true)]
    public function testAnAjaxSwitchWhoseStoreFailsIsAnsweredWithTheSectionsNameAndTheCauseIsLogged(): void
    {
        $logger = $this->failEverySwitch();

        $this->post('off', self::SYSTEM, true);

        self::assertSame([], $this->switches()->off());
        $response = $this->response();
        self::assertSame(500, $response->getHttpResponseCode());
        self::assertStringContainsString('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame(['error' => self::FAILED], json_decode((string) $response->getBody(), true));
        $this->assertSessionMessages(self::isEmpty());
        $logged = array_values(array_filter(
            $logger->messages('error'),
            static fn (string $message): bool => str_contains($message, FailingSectionSwitches::STORAGE_ERROR)
        ));
        self::assertCount(1, $logged, 'The exception is logged once, with the storage error it carries');
        self::assertStringContainsString('could not be switched off', $logged[0]);
    }

    #[AppIsolation(true)]
    public function testAFormPostWhoseStoreFailsSaysSoWithTheSectionsName(): void
    {
        $this->failEverySwitch();

        $this->post('off', self::SYSTEM);

        self::assertSame([], $this->switches()->off());
        $this->assertSessionMessages(self::equalTo([self::FAILED]), MessageInterface::TYPE_ERROR);
        $this->assertRedirect(self::stringContains('mcpserver/server/index'));
    }

    public function testAnAjaxSwitchOfACodeNoSectionHasIsRefusedAndChangesNothing(): void
    {
        $this->post('off', 'Vendor_Gone::area', true);

        self::assertSame([], $this->switches()->off());
        $response = $this->response();
        self::assertSame(400, $response->getHttpResponseCode());
        self::assertSame(['error' => 'Choose a section.'], json_decode((string) $response->getBody(), true));
        $this->assertSessionMessages(self::isEmpty());
    }

    /**
     * Post the section to its switch, as a form or as the card's script does
     *
     * @param string $switch "off" or "on"
     * @param string $section
     * @param bool $ajax
     * @return void
     */
    private function post(string $switch, string $section, bool $ajax = false): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $values = $ajax ? ['section' => $section, 'isAjax' => 'true'] : ['section' => $section];
        $request->setMethod(HttpRequest::METHOD_POST)->setParams($values)->setPostValue($values);

        $this->dispatch('backend/mcpserver/section/' . $switch);
    }

    /**
     * Make every switch fail to store, and record what is logged; the application is rebuilt after the test
     *
     * @return RecordingLogger
     */
    private function failEverySwitch(): RecordingLogger
    {
        $this->_objectManager->configure(['preferences' => [
            SectionSwitchesInterface::class => FailingSectionSwitches::class,
            LoggerInterface::class => RecordingLogger::class,
        ]]);
        $logger = $this->_objectManager->get(LoggerInterface::class);
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * How many abilities of the whole catalog clients may call, read from the catalog now
     *
     * Read only after a dispatch: reading the catalog first leaves the admin request without its rights.
     *
     * @return int
     */
    private function exposedTotal(): int
    {
        $rows = Bootstrap::getObjectManager()->get(AbilityRows::class);
        self::assertInstanceOf(AbilityRows::class, $rows);

        return count(array_filter(
            $rows->recount(),
            static fn (array $row): bool => Exposure::from($row['exposure'])->allowsCall()
        ));
    }

    /**
     * How many abilities of a section clients may call
     *
     * @param list<array{code: string, exposed: int}> $sections
     * @param string $code
     * @return int
     */
    private function exposedOf(array $sections, string $code): int
    {
        foreach ($sections as $section) {
            if ($section['code'] === $code) {
                return $section['exposed'];
            }
        }

        return 0;
    }

    /**
     * @return CatalogSections
     */
    private function sections(): CatalogSections
    {
        $sections = Bootstrap::getObjectManager()->get(CatalogSections::class);
        self::assertInstanceOf(CatalogSections::class, $sections);

        return $sections;
    }

    /**
     * @return Response
     */
    private function response(): Response
    {
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * A header's value
     *
     * @param Response $response
     * @param string $name
     * @return string
     */
    private function header(Response $response, string $name): string
    {
        $header = $response->getHeader($name);
        self::assertInstanceOf(HeaderInterface::class, $header, $name . ' is sent once');

        return $header->getFieldValue();
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
