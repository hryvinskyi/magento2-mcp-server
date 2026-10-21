<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Protocol;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityPageInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\Exposure;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\Data\Ability;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use Hryvinskyi\McpServer\Model\Protocol\JsonEncoder;
use Hryvinskyi\McpServer\Model\Protocol\Tool\GetAbilityInfo;
use Hryvinskyi\McpServer\Test\Unit\Fake\ConstructingObjectManager;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeTool;
use Hryvinskyi\McpServer\Test\Unit\Fake\FixedExposurePolicy;
use Hryvinskyi\McpServer\Test\Unit\Fake\ProtocolKit;
use Hryvinskyi\McpServer\Test\Unit\Fake\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * The JSON the server writes, checked as the raw text a client parses: JSON objects that may be empty stay objects,
 * and broken UTF-8 never fails an answer
 */
class JsonEncoderTest extends TestCase
{
    /**
     * @var ProtocolKit
     */
    private ProtocolKit $kit;

    protected function setUp(): void
    {
        $this->kit = new ProtocolKit($this);
    }

    public function testAPingAnswerEncodesItsResultAsAnEmptyObject(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->kit->server()->handle(
            '{"jsonrpc":"2.0","id":1,"method":"ping"}',
            $this->kit->context($session->getId())
        );

        self::assertStringContainsString('"result":{}', $answer->getBody());
    }

    public function testAnInitializeAnswerEncodesCapabilitiesAndTheirToolsAsObjects(): void
    {
        $answer = $this->kit->server()->handle(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
            $this->kit->context()
        );

        self::assertStringContainsString('"capabilities":{"tools":{"listChanged":false}}', $answer->getBody());
    }

    public function testAToolWithoutParametersIsListedWithEmptyObjectProperties(): void
    {
        $session = $this->kit->openSession();

        $answer = $this->kit->server([new FakeTool()])->handle(
            '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
            $this->kit->context($session->getId())
        );

        self::assertStringContainsString(
            '"inputSchema":{"type":"object","properties":{}}',
            $answer->getBody()
        );
    }

    public function testAnAbilityWithoutParametersHasAnInputSchemaWithEmptyObjectProperties(): void
    {
        $session = $this->kit->openSession();
        $source = new FakeAbilitySource('webapi', [
            new Ability(
                'directory.country_information_acquirer.get_countries_info',
                'Countries',
                'Magento_Directory',
                'webapi',
                'GET /V1/directory/countries',
                AbilityAccess::Read,
                ['anonymous']
            ),
        ]);
        $catalog = new AbilityCatalog(
            new AbilityPageInterfaceFactory(
                new ConstructingObjectManager([AbilityPageInterface::class => AbilityPage::class])
            ),
            new RecordingLogger(),
            ['webapi' => $source]
        );
        $tool = new GetAbilityInfo(
            $catalog,
            new FixedExposurePolicy(Exposure::Exposed),
            $this->kit->argumentReader(),
            $this->kit->toolResults()
        );

        $answer = $this->kit->server([$tool])->handle(
            '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_ability_info",'
            . '"arguments":{"name":"directory.country_information_acquirer.get_countries_info"}}}',
            $this->kit->context($session->getId())
        );

        self::assertStringContainsString('\"properties\":{}', $answer->getBody());
        self::assertStringContainsString('"inputSchema":{"type":"object","properties":{}', $answer->getBody());
    }

    public function testInvalidUtf8IsReplacedInsteadOfFailing(): void
    {
        $json = (new JsonEncoder())->encode(['name' => "caf\xC3"]);

        self::assertSame("{\"name\":\"caf\u{FFFD}\"}", $json);
    }

    public function testInvalidUtf8InAToolResultStillGivesAnAnswer(): void
    {
        $result = $this->kit->toolResults()->success(['sku' => "bad\xFFbyte"]);

        self::assertIsArray($result['content']);
        self::assertSame(
            [['type' => 'text', 'text' => "{\"sku\":\"bad\u{FFFD}byte\"}"]],
            $result['content']
        );
    }

    public function testSlashesAndUnicodeAreWrittenAsTheyAreAndFloatsKeepTheirFraction(): void
    {
        $json = (new JsonEncoder())->encode(['url' => 'https://example.test/a', 'name' => 'Київ', 'price' => 10.0]);

        self::assertSame('{"url":"https://example.test/a","name":"Київ","price":10.0}', $json);
    }

    public function testAValueJsonCannotHoldThrows(): void
    {
        $this->expectException(\JsonException::class);

        (new JsonEncoder())->encode(NAN);
    }
}
