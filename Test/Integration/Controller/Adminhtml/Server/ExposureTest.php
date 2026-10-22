<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\MessageInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Putting abilities on the deny-list and asking for confirmation, from a row and from the catalog's selection
 *
 * Each test dispatches once: the test application serves a second dispatch of one test from the first one's state.
 */
#[DbIsolation(true)]
class ExposureTest extends AbstractBackendController
{
    use SwitchesGraphQlSourceOff;

    private const LIST_PRODUCTS = 'catalog.product_repository.get_list';
    private const GET_PRODUCT = 'catalog.product_repository.get';

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::manage';

    /**
     * @var string
     */
    protected $uri = 'backend/mcpserver/server/exposure/change/deny/name/catalog.product_repository.get_list';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    public function testARowPutsItsAbilityOnTheDenyList(): void
    {
        $this->post(['change' => 'deny', 'name' => self::LIST_PRODUCTS]);

        self::assertTrue($this->overrides()->get(self::LIST_PRODUCTS)?->isDenied());
        $this->assertSessionMessages(
            self::equalTo(['A total of 1 abilities were changed.']),
            MessageInterface::TYPE_SUCCESS
        );
    }

    public function testTakingAnAbilityOffTheDenyListKeepsItsConfirmation(): void
    {
        $override = $this->overrides()->get(self::LIST_PRODUCTS);
        $this->overrides()->save(
            ($override ?? $this->newOverride())
                ->setAbility(self::LIST_PRODUCTS)
                ->setDenied(true)
                ->setNeedsConfirmation(true)
        );

        $this->post(['change' => 'allow', 'name' => self::LIST_PRODUCTS]);

        $kept = $this->overrides()->get(self::LIST_PRODUCTS);
        self::assertSame([false, true], [$kept?->isDenied(), $kept?->needsConfirmation()]);
    }

    public function testTheSelectedAbilitiesNeedConfirmationAndANameNoSourceOffersIsSkipped(): void
    {
        $this->post([
            'change' => 'confirm',
            'namespace' => 'hryvinskyi_mcp_ability_listing',
            'selected' => [self::LIST_PRODUCTS, self::GET_PRODUCT, 'catalog.nothing.here'],
        ]);

        self::assertTrue($this->overrides()->get(self::LIST_PRODUCTS)?->needsConfirmation());
        self::assertTrue($this->overrides()->get(self::GET_PRODUCT)?->needsConfirmation());
        self::assertNull($this->overrides()->get('catalog.nothing.here'));
        $this->assertSessionMessages(
            self::equalTo(['A total of 2 abilities were changed.']),
            MessageInterface::TYPE_SUCCESS
        );
    }

    public function testSelectingAllChangesEveryAbilityTheSearchFindsExceptThoseLeftOut(): void
    {
        $this->post([
            'change' => 'confirm',
            'namespace' => 'hryvinskyi_mcp_ability_listing',
            'excluded' => [self::GET_PRODUCT],
            'search' => 'product_repository get',
            'filters' => ['source' => 'webapi', 'placeholder' => 'true'],
        ]);

        self::assertTrue($this->overrides()->get(self::LIST_PRODUCTS)?->needsConfirmation());
        self::assertNull($this->overrides()->get(self::GET_PRODUCT), 'Left out of the selection');
        self::assertNull(
            $this->overrides()->get('catalog.product_repository.save'),
            'Not found by the search'
        );
    }

    public function testAnUnknownChangeChangesNothing(): void
    {
        $this->post(['change' => 'erase', 'name' => self::LIST_PRODUCTS]);

        self::assertNull($this->overrides()->get(self::LIST_PRODUCTS));
        $this->assertSessionMessages(self::equalTo(['Choose what to change.']), MessageInterface::TYPE_ERROR);
    }

    /**
     * Dispatch the change as a form post
     *
     * @param array<string, mixed> $parameters
     * @return void
     */
    private function post(array $parameters): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setMethod(HttpRequest::METHOD_POST)->setParams($parameters)->setPostValue($parameters);

        $this->dispatch('backend/mcpserver/server/exposure');
    }

    /**
     * @return AbilityOverrideRepositoryInterface
     */
    private function overrides(): AbilityOverrideRepositoryInterface
    {
        $overrides = Bootstrap::getObjectManager()->get(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepositoryInterface::class, $overrides);

        return $overrides;
    }

    /**
     * A blank override, as the container builds one
     *
     * @return AbilityOverrideInterface
     */
    private function newOverride(): AbilityOverrideInterface
    {
        $override = Bootstrap::getObjectManager()->create(AbilityOverrideInterface::class);
        self::assertInstanceOf(AbilityOverrideInterface::class, $override);

        return $override;
    }
}
