<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Framework\Acl\Builder as AclBuilder;
use Laminas\Http\Headers;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The ability catalog grid's data as the listing loads it: searched, filtered, paged, with each exposure explained
 */
#[DbIsolation(true)]
class GridDataTest extends AbstractBackendController
{
    use SwitchesGraphQlSourceOff;

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::view';

    /**
     * @var string
     */
    protected $uri = 'backend/mui/index/render/namespace/hryvinskyi_mcp_ability_listing';

    protected function tearDown(): void
    {
        $acl = Bootstrap::getObjectManager()->get(AclBuilder::class);
        self::assertInstanceOf(AclBuilder::class, $acl);
        $acl->resetRuntimeAcl();
        parent::tearDown();
    }

    #[Config('hryvinskyi_mcp_server/general/read_only_mode', '1')]
    public function testTheCatalogIsSearchedFilteredAndPagedWithEachExposureExplained(): void
    {
        $data = $this->render([
            'search' => 'catalog',
            'filters' => ['access' => 'write', 'placeholder' => 'true'],
            'paging' => ['pageSize' => '2', 'current' => '1'],
            'sorting' => ['field' => 'name', 'direction' => 'asc'],
        ]);

        self::assertGreaterThan(2, $data['totalRecords'], 'Every match is counted, not only the page');
        self::assertCount(2, $data['items']);
        $first = $data['items'][0];
        self::assertIsArray($first);
        self::assertSame('write', $first['access'] ?? null);
        self::assertIsString($first['exposure'] ?? null);
        self::assertStringContainsString('Blocked: read-only mode', $first['exposure']);
        self::assertIsString($first['acl'] ?? null);
        self::assertMatchesRegularExpression(
            '#^(<span class="mcp-acl">Magento_[A-Za-z]+::[a-z_]+<\/span>)+$#',
            $first['acl']
        );
        self::assertArrayNotHasKey('actions', $first, 'Read-only mode decides a blocked row: it offers no change');
    }

    /**
     * Load the grid's data through the listing's own render action
     *
     * @param array<string, mixed> $parameters
     * @return array{totalRecords: int, items: list<mixed>}
     */
    private function render(array $parameters): array
    {
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setParams(['namespace' => 'hryvinskyi_mcp_ability_listing', 'isAjax' => 'true'] + $parameters);
        $headers = $request->getHeaders();
        self::assertInstanceOf(Headers::class, $headers);
        $headers->addHeaderLine('Accept', 'application/json');

        $this->dispatch('backend/mui/index/render');

        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded, (string) $response->getBody());
        self::assertIsInt($decoded['totalRecords'] ?? null);
        self::assertIsArray($decoded['items'] ?? null);

        return ['totalRecords' => $decoded['totalRecords'], 'items' => array_values($decoded['items'])];
    }
}
