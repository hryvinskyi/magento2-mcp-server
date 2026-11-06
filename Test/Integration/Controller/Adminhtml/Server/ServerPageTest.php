<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Server;

use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Test\Integration\Fixture\CreatesIntegrations;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\LayoutInterface;
use Magento\Integration\Model\Integration;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User;

/**
 * Stores › MCP Server through the real admin stack: the server's state, how a client connects, and the catalog
 *
 * The GraphQL abilities are switched off, so building the catalog reads no schema over the network. No assertion
 * prints an integration's token: tokens are looked for with str_contains().
 */
#[DbIsolation(true)]
class ServerPageTest extends AbstractBackendController
{
    use CreatesIntegrations;
    use SwitchesGraphQlSourceOff;

    private const CARD = '[data-role="mcp-connection-card"]';
    private const PICKER = 'Hryvinskyi_McpServer/js/integration-picker';
    private const BEARER_WARNING = 'Integration tokens are not accepted as Bearer tokens';
    private const KIT_STYLESHEET = 'Hryvinskyi_AdminUi/css/admin-ui.css';
    private const PAGE_STYLESHEET = 'Hryvinskyi_McpServer/css/mcp-server.css';
    private const OVERVIEW_BLOCK = 'hryvinskyi.mcp.server.overview';
    private const LISTING = 'hryvinskyi_mcp_ability_listing';

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::view';

    /**
     * @var string
     */
    protected $uri = 'backend/mcpserver/server/index';

    #[Config('hryvinskyi_mcp_server/general/enabled', '0')]
    public function testThePageShowsHowToConnectAndNeverASecret(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        self::assertStringContainsString('/rest/all/V1/mcp', $body);
        self::assertStringContainsString('hryvinskyi:mcp:serve --admin-user=&lt;login&gt;', $body);
        self::assertSame(4, preg_match_all('/data-snippet="(claude|cursor)-(http|stdio)"/', $body));
        self::assertStringContainsString('Bearer &lt;integration-token&gt;', $body);
        self::assertStringContainsString('mcp-remote', $body, 'Claude Desktop reaches HTTP servers through a bridge');
        self::assertStringContainsString('The MCP server is disabled', $body);
        self::assertStringContainsString('hryvinskyi_mcp_ability_listing', $body);
        $page = $this->page($body);
        $endpoint = trim($this->only($page, '//*[@id="mcp-endpoint"]')->textContent);
        self::assertStringEndsWith('/rest/all/V1/mcp', $endpoint);
        $card = $this->cardComponents($body);
        self::assertSame(
            [
                'copied' => 'Copied',
                'connection' => ['name' => 'magento', 'url' => $endpoint, 'placeholder' => '<integration-token>'],
            ],
            $card['Hryvinskyi_McpServer/js/connection-card'] ?? null,
            'The script gets the connection and the placeholder, never a token'
        );
        self::assertStringContainsString(
            'mcpserver/server/extension',
            $this->only($page, '//a[@data-role="mcp-extension-download"]')->getAttribute('href')
        );
        foreach (['cursor', 'vscode', 'claude-code'] as $target) {
            $this->only($page, '//*[@data-install="' . $target . '"]');
        }
        self::assertSame(4, $this->all($page, '//*[@data-token-slot]')->length);
        $field = $this->only($page, '//input[@data-role="mcp-token"]');
        self::assertSame('password', $field->getAttribute('type'));
        self::assertSame(
            'new-password',
            $field->getAttribute('autocomplete'),
            'A password field marked "off" is still filled with the admin\'s saved login password'
        );
        self::assertFalse($field->hasAttribute('name'), 'A field without a name is never part of a submission');
        self::assertSame(0, $this->all($page, 'ancestor::form', $field)->length, 'The token field sits in no form');
        foreach (['cursor', 'vscode'] as $target) {
            $link = $this->only($page, '//a[@data-install="' . $target . '"]');
            self::assertTrue($link->hasAttribute('hidden'), $target . ' waits hidden for a valid token');
            self::assertFalse($link->hasAttribute('href'), $target . ' gets its address from the script only');
        }
        self::assertTrue($this->only($page, '//*[@data-role="mcp-token-hint"]')->hasAttribute('hidden'));
        $group = $this->only($page, '//*[@data-role="mcp-claude-code"]');
        self::assertTrue($group->hasAttribute('hidden'), 'The Claude Code group waits hidden until it is filled');
        $command = $this->only($page, '//*[@data-role="mcp-claude-code"]//code[@data-install="claude-code"]');
        self::assertSame('', trim($command->textContent), 'The command is printed by the script only');
        $this->only(
            $page,
            '//*[@data-role="mcp-claude-code"]//button[@data-copy-target="mcp-claude-code-command"]'
        );
    }

    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    #[Config('hryvinskyi_mcp_server/general/read_only_mode', '1')]
    public function testAnEnabledServerInReadOnlyModeSaysSo(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        self::assertStringContainsString('Enabled · read-only mode', $body);
        self::assertStringContainsString('Read-only mode is on', $body);
        self::assertStringNotContainsString('The MCP server is disabled', $body);
        $state = $this->only($this->page($body), '//*[@data-role="mcp-state"]');
        self::assertStringContainsString(
            ' hui-badge--warn ',
            ' ' . $this->normalised($state->getAttribute('class')) . ' ',
            'The state badge draws read-only mode in the warning tone'
        );
    }

    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    public function testThePageListsTheSectionsAndSaysPersonalDataIsHidden(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        $page = $this->page($body);
        $this->only($page, '//section[@data-role="mcp-sections"]');
        $system = $this->only(
            $page,
            '//section[@data-role="mcp-sections"]//*[@data-role="mcp-section"]'
            . '[.//*[@id][normalize-space()="System"]]'
        );
        $switch = $this->only($page, './/form[@method="post"]//button[@type="submit"]', $system);
        self::assertSame('Switch off', $this->normalised($switch->textContent), 'The switch says what it does');
        self::assertSame('true', $switch->getAttribute('aria-checked'), 'The switch draws the section as on');
        self::assertSame('Magento_Backend::system', $system->getAttribute('data-section'), 'The row names its section');
        self::assertArrayHasKey(
            'Hryvinskyi_McpServer/js/sections-card',
            $this->componentsOn($body, '[data-role="mcp-sections"]'),
            'The switches change a section without reloading the page'
        );
        $personalData = $this->only(
            $page,
            '//*[@data-role="mcp-facts"]//dt[normalize-space()="Personal data"]/following-sibling::dd[1]'
        );
        self::assertSame('Hidden', $this->normalised($personalData->textContent));
        self::assertStringNotContainsString('Some abilities send personal data', $body);
        $total = $this->only(
            $page,
            '//*[@data-role="mcp-facts"]//dt[normalize-space()="Exposed"]/following-sibling::dd[1]'
        );
        self::assertSame(
            'mcp-exposed-total',
            $total->getAttribute('data-role'),
            'The sections card redraws the total after a switch'
        );
        self::assertMatchesRegularExpression('/^\d+$/', $this->normalised($total->textContent));
    }

    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    #[Config('hryvinskyi_mcp_server/personal_data/hide', '0')]
    public function testAServerThatSendsPersonalDataWarnsThatAnAgreementMustCoverIt(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        self::assertStringContainsString('Some abilities send personal data to clients', $body);
        self::assertStringContainsString('Shown to every ability', $body);
    }

    /**
     * The page opts in to the admin UI kit: the kit's stylesheet is linked before the page's own, whose rules
     * override kit rules of the same weight by that order, and Magento resolves the kit's file
     *
     * A link's address is built without touching the file it names, so the source file is resolved on its own.
     *
     * @return void
     */
    public function testThePageLinksTheKitStylesheetBeforeItsOwn(): void
    {
        $this->dispatch($this->uri);
        $page = $this->page($this->body());

        $order = [];
        foreach ($this->all($page, '//head/link[@rel="stylesheet"]') as $index => $link) {
            self::assertInstanceOf(DOMElement::class, $link);
            foreach ([self::KIT_STYLESHEET, self::PAGE_STYLESHEET] as $stylesheet) {
                if (str_contains($link->getAttribute('href'), $stylesheet)) {
                    $order[$stylesheet] = $index;
                }
            }
        }
        $kit = $order[self::KIT_STYLESHEET] ?? null;
        $own = $order[self::PAGE_STYLESHEET] ?? null;
        self::assertIsInt($kit, 'The kit stylesheet is linked in the head');
        self::assertIsInt($own, 'The page stylesheet is linked in the head');
        self::assertLessThan($own, $kit, 'The kit comes first, so the page overrides it by order');

        $assets = $this->_objectManager->get(AssetRepository::class);
        self::assertInstanceOf(AssetRepository::class, $assets);
        $source = $assets->createAsset('Hryvinskyi_AdminUi::css/admin-ui.css', ['area' => 'adminhtml'])
            ->getSourceFile();
        self::assertFileExists($source);
    }

    /**
     * The overview sits under the kit's full root and stays a direct child of the page's content; only the catalog
     * listing is wrapped, in the kit's tokens-only root
     *
     * A block another module places before or after the overview is ordered among the content's children, so the
     * overview must stay one of them. The catalog's skin reads the kit's colours from its wrapper, while the kit's base
     * rules reach only the markup this module prints. The parts the page shows and hides with the attribute start
     * hidden; the kit's rule for the attribute applies inside its full root only.
     *
     * @return void
     */
    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    public function testTheOverviewStaysInTheContentAndOnlyTheCatalogIsWrappedInTheTokensRoot(): void
    {
        $inactive = $this->createIntegration(
            'mcp-test not ready',
            ['Hryvinskyi_McpServer::access'],
            Integration::STATUS_INACTIVE
        );

        $this->dispatch($this->uri);
        $page = $this->page($this->body());

        $layout = $this->_objectManager->get(LayoutInterface::class);
        self::assertInstanceOf(LayoutInterface::class, $layout);
        self::assertSame(
            'content',
            $layout->getParentName(self::OVERVIEW_BLOCK),
            'The overview is a direct child of the page content, where other modules order their blocks around it'
        );
        $catalog = $layout->getParentName(self::LISTING);
        self::assertIsString($catalog, 'The catalog listing is placed in the layout');
        self::assertNotSame('content', $catalog, 'The catalog listing is wrapped');
        self::assertSame('content', $layout->getParentName($catalog), 'The wrapper is a child of the page content');
        $children = $layout->getChildNames('content');
        self::assertLessThan(
            array_search($catalog, $children, true),
            array_search(self::OVERVIEW_BLOCK, $children, true),
            'The catalog follows the overview'
        );

        $connection = '//section[@aria-labelledby="mcp-connection-title"]';
        $listing = '//*[contains(@data-bind, "' . self::LISTING . '.' . self::LISTING . '")]';
        $this->only($page, $connection);
        self::assertGreaterThan(0, $this->all($page, $listing)->length, 'The catalog listing is rendered');
        self::assertSame(
            1,
            $this->all($page, $connection . '/ancestor::*[' . $this->hasClass('hui') . ']')->length,
            'The connection card sits under the kit root that carries the base rules'
        );
        self::assertSame(
            0,
            $this->all($page, $connection . '/ancestor::*[' . $this->hasClass('hui-tokens') . ']')->length,
            'The overview is not wrapped'
        );
        $wrapper = $this->only($page, '//*[' . $this->hasClass('hui-tokens') . ']');
        self::assertSame(
            $this->all($page, $listing)->length,
            $this->all($page, '.' . $listing, $wrapper)->length,
            'The tokens-only root holds the catalog listing'
        );
        self::assertSame(
            0,
            $this->all($page, $listing . '/ancestor-or-self::*[' . $this->hasClass('hui') . ']')->length,
            'The kit\'s base rules stay off the catalog listing'
        );

        self::assertTrue($this->only($page, $this->note($inactive['id']))->hasAttribute('hidden'));
        foreach (['cursor', 'vscode'] as $target) {
            self::assertTrue(
                $this->only($page, '//a[@data-install="' . $target . '"]')->hasAttribute('hidden'),
                $target . ' starts hidden'
            );
        }
    }

    /**
     * The picker lists every integration by name and readiness, explains the ones that are not ready, and never
     * prints a token; listing them leaves the admin's own rights intact for the rest of the page
     *
     * Reading an integration's resources rebuilds Magento's request-wide ACL for that integration's role. The section
     * switches, the OAuth settings link and the catalog's mass actions are all decided by the admin's ACL after the
     * integrations were listed, so each of them is missing when that ACL is not restored.
     *
     * @return void
     */
    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    #[Config('oauth/consumer/enable_integration_as_bearer', '0', ScopeInterface::SCOPE_STORE, 'default')]
    public function testThePickerListsTheIntegrationsWithoutTheirTokensAndKeepsTheAdminsRights(): void
    {
        $ready = $this->createIntegration('mcp-test ready <agent>', ['Hryvinskyi_McpServer::access']);
        $inactive = $this->createIntegration(
            'mcp-test inactive',
            ['Hryvinskyi_McpServer::access'],
            Integration::STATUS_INACTIVE
        );

        $this->dispatch($this->uri);
        $body = $this->body();
        $page = $this->page($body);

        foreach ([$ready, $inactive] as $integration) {
            self::assertTrue($integration['token'] !== '', 'The integration has a token that could leak');
            self::assertFalse(str_contains($body, $integration['token']), 'The page carries no integration token');
        }
        $select = $this->only($page, '//select[@id="mcp-integration"][@data-role="mcp-integration"]');
        self::assertSame(
            'off',
            $select->getAttribute('autocomplete'),
            'A reload restores no integration in the select while the token field comes back empty'
        );
        $first = $this->only($page, '//select[@data-role="mcp-integration"]/option[1]');
        self::assertSame('', $first->getAttribute('value'), 'The first option leaves the token to be pasted');
        self::assertSame(0, $this->all($page, 'ancestor::form', $select)->length, 'The select sits in no form');
        $readyOption = $this->only($page, $this->option($ready['id']));
        self::assertSame('1', $readyOption->getAttribute('data-ready'));
        self::assertSame('mcp-test ready <agent>', trim($readyOption->textContent), 'The name is printed escaped');
        self::assertStringContainsString('mcp-test ready &lt;agent&gt;', $body);
        $inactiveOption = $this->only($page, $this->option($inactive['id']));
        self::assertSame('0', $inactiveOption->getAttribute('data-ready'));
        self::assertSame('mcp-test inactive', trim($inactiveOption->textContent));

        self::assertSame(0, $this->all($page, $this->note($ready['id']))->length, 'A ready integration needs no note');
        $note = $this->only($page, $this->note($inactive['id']));
        self::assertTrue($note->hasAttribute('hidden'), 'The note waits hidden until the integration is picked');
        self::assertStringContainsString(
            IntegrationReadiness::Inactive->reason()->render(),
            $this->normalised($note->textContent)
        );
        $edit = $this->only($page, $this->note($inactive['id']) . '/a');
        self::assertStringContainsString('integration/edit/id/' . $inactive['id'], $edit->getAttribute('href'));
        self::assertSame('_blank', $edit->getAttribute('target'));
        self::assertSame('noopener', $edit->getAttribute('rel'));

        $new = $this->only($page, '//a[@data-role="mcp-new-integration"]');
        self::assertStringContainsString('admin/integration/new', $new->getAttribute('href'));
        self::assertSame('_blank', $new->getAttribute('target'));
        self::assertSame('noopener', $new->getAttribute('rel'));
        $status = $this->only($page, '//*[@data-role="mcp-integration-status"]');
        self::assertSame('polite', $status->getAttribute('aria-live'));
        $this->only($page, '//input[@data-role="mcp-token"]');

        $components = $this->cardComponents($body);
        self::assertArrayHasKey('Hryvinskyi_McpServer/js/connection-card', $components, 'The card still mounts');
        $picker = $components[self::PICKER] ?? null;
        self::assertIsArray($picker, 'The picker mounts on the card');
        self::assertIsString($picker['tokenUrl'] ?? null);
        self::assertStringContainsString('mcpserver/server/integrationtoken', $picker['tokenUrl']);
        self::assertIsString($picker['formKey'] ?? null);
        self::assertNotSame('', $picker['formKey']);
        self::assertSame(
            [
                'loading' => 'Getting the token…',
                'refused' => 'This integration\'s token cannot be used.',
                'failed' => 'The token could not be fetched. Reload the page and pick again, or paste it by hand.',
            ],
            $picker['wording'] ?? null
        );

        self::assertStringContainsString('Switch off', $body, 'The sections card still offers its switches');
        $settings = $this->only($page, '//*[@data-role="mcp-bearer-warning"]//a');
        self::assertStringContainsString('system_config/edit/section/oauth', $settings->getAttribute('href'));
        self::assertStringContainsString('Add to deny-list', $body, 'The catalog still offers its mass actions');
    }

    /**
     * Showing integration tokens is a right of its own in Magento; without it the admin pastes a token by hand
     *
     * @return void
     */
    #[Config('hryvinskyi_mcp_server/general/enabled', '1')]
    public function testAnAdminWhoMayNotSeeIntegrationTokensGetsOnlyTheTokenField(): void
    {
        $integration = $this->createIntegration('mcp-test hidden from the admin', ['Hryvinskyi_McpServer::access']);
        $user = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $user);
        $builder = $this->_objectManager->get(AclBuilder::class);
        self::assertInstanceOf(AclBuilder::class, $builder);
        $builder->getAcl()->deny($user->getRoles(), 'Magento_Integration::integrations');

        $this->dispatch($this->uri);
        $body = $this->body();
        $page = $this->page($body);

        self::assertSame(0, $this->all($page, '//select[@data-role="mcp-integration"]')->length, 'No picker');
        self::assertSame(0, $this->all($page, '//*[@data-integration-note]')->length, 'No notes');
        self::assertSame(0, $this->all($page, '//a[@data-role="mcp-new-integration"]')->length, 'No create link');
        self::assertStringNotContainsString('mcp-test hidden from the admin', $body, 'No integration is named');
        self::assertFalse(str_contains($body, $integration['token']), 'The page carries no integration token');
        self::assertArrayNotHasKey(self::PICKER, $this->cardComponents($body), 'The picker is not mounted');
        $this->only($page, '//input[@data-role="mcp-token"]');
        self::assertStringContainsString('It stays in this browser tab and is never sent to the store.', $body);
        self::assertStringNotContainsString('Picking an integration', $body, 'The help speaks of pasting only');
    }

    #[Config('oauth/consumer/enable_integration_as_bearer', '0', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheCardWarnsWhenIntegrationTokensAreNotAcceptedAsBearerTokens(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        $warning = $this->only(
            $this->page($body),
            '//section[@aria-labelledby="mcp-connection-title"]//*[@data-role="mcp-bearer-warning"]'
        );
        self::assertStringContainsString(self::BEARER_WARNING, $this->normalised($warning->textContent));
    }

    #[Config('oauth/consumer/enable_integration_as_bearer', '1', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheCardDoesNotWarnWhenIntegrationTokensAreAcceptedAsBearerTokens(): void
    {
        $this->dispatch($this->uri);
        $body = $this->body();

        self::assertStringNotContainsString(self::BEARER_WARNING, $body);
        self::assertSame(0, $this->all($this->page($body), '//*[@data-role="mcp-bearer-warning"]')->length);
    }

    /**
     * The components the page mounts on the connection card, from every x-magento-init script that names it
     *
     * Magento merges every script's components for one selector into the element's mount list, so two scripts that
     * name the card mount both.
     *
     * @param string $body
     * @return array<mixed>
     */
    private function cardComponents(string $body): array
    {
        return $this->componentsOn($body, self::CARD);
    }

    /**
     * The components the page mounts on one selector, from every x-magento-init script that names it
     *
     * @param string $body
     * @param string $selector
     * @return array<mixed>
     */
    private function componentsOn(string $body, string $selector): array
    {
        preg_match_all('#<script type="text/x-magento-init">(.*?)</script>#s', $body, $scripts);
        $components = [];
        $found = false;
        foreach ($scripts[1] as $script) {
            $config = json_decode($script, true);
            if (is_array($config) && isset($config[$selector]) && is_array($config[$selector])) {
                $components = array_replace_recursive($components, $config[$selector]);
                $found = true;
            }
        }
        self::assertTrue($found, 'The page mounts components on ' . $selector);

        return $components;
    }

    /**
     * The XPath condition for an element whose class list holds this class
     *
     * @param string $class
     * @return string
     */
    private function hasClass(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
    }

    /**
     * The query for the picker's option of this integration
     *
     * @param int $id
     * @return string
     */
    private function option(int $id): string
    {
        return '//select[@data-role="mcp-integration"]/option[@value="' . $id . '"]';
    }

    /**
     * The query for the note about this integration
     *
     * @param int $id
     * @return string
     */
    private function note(int $id): string
    {
        return '//*[@data-integration-note="' . $id . '"]';
    }

    /**
     * The text with each run of white space made one space
     *
     * @param string $text
     * @return string
     */
    private function normalised(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The response's HTML as a document to query
     *
     * @param string $body
     * @return DOMXPath
     */
    private function page(string $body): DOMXPath
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadHTML($body, LIBXML_NOERROR | LIBXML_NOWARNING));

        return new DOMXPath($document);
    }

    /**
     * The nodes the query finds
     *
     * @param DOMXPath $page
     * @param string $query
     * @param DOMNode|null $context
     * @return DOMNodeList<DOMNameSpaceNode|DOMNode>
     */
    private function all(DOMXPath $page, string $query, ?DOMNode $context = null): DOMNodeList
    {
        $nodes = $page->query($query, $context);
        self::assertInstanceOf(DOMNodeList::class, $nodes, 'Invalid query ' . $query);

        return $nodes;
    }

    /**
     * The one element the query finds
     *
     * @param DOMXPath $page
     * @param string $query
     * @param DOMNode|null $context
     * @return DOMElement
     */
    private function only(DOMXPath $page, string $query, ?DOMNode $context = null): DOMElement
    {
        $nodes = $this->all($page, $query, $context);
        self::assertSame(1, $nodes->length, 'Exactly one element matches ' . $query);
        $element = $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    /**
     * The page, which must have been answered
     *
     * @return string
     */
    private function body(): string
    {
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return (string) $response->getBody();
    }
}
