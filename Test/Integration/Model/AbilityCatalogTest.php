<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Model;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\Catalog\AbilitySourceResolverInterface;
use Hryvinskyi\McpServer\Model\Data\AbilityPage;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The catalog as the container builds it: its preference, the resolver that shares its index, and a pool of sources
 */
class AbilityCatalogTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use SwitchesGraphQlSourceOff;

    public function testThePreferenceIsTheCatalogAndTheResolverIsTheSameInstance(): void
    {
        $catalog = Bootstrap::getObjectManager()->get(AbilityCatalogInterface::class);
        $resolver = Bootstrap::getObjectManager()->get(AbilitySourceResolverInterface::class);

        self::assertInstanceOf(AbilityCatalog::class, $catalog);
        self::assertSame($catalog, $resolver);
    }

    public function testTheWiredCatalogRefusesAnUnknownName(): void
    {
        $catalog = Bootstrap::getObjectManager()->get(AbilityCatalogInterface::class);
        self::assertInstanceOf(AbilityCatalogInterface::class, $catalog);

        $this->expectException(NoSuchEntityException::class);

        $catalog->get('hryvinskyi_mcp_server.no_such.ability');
    }

    public function testACatalogBuiltWithASourceFindsSearchesAndDelegates(): void
    {
        $list = $this->ability('catalog.product_repository.get_list', 'Retrieve products', AbilityAccess::Read);
        $save = $this->ability('catalog.product_repository.save', 'Save a product', AbilityAccess::Write);
        $source = new FakeAbilitySource('test', [$save, $list], false);
        $catalog = Bootstrap::getObjectManager()->create(AbilityCatalog::class, ['sources' => ['test' => $source]]);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        self::assertSame($list, $catalog->get('catalog.product_repository.get_list'));
        $page = $catalog->search('product', 'Magento_Catalog', null, 1, 0);
        self::assertInstanceOf(AbilityPage::class, $page);
        self::assertSame(2, $page->getTotal());
        self::assertSame([$list], $page->getItems());
        self::assertSame([$save], $catalog->search('save', null, AbilityAccess::Write, 25, 0)->getItems());
        self::assertSame('test', $catalog->schemaOf($save)['description']);
        self::assertFalse($catalog->isAllowed($save));
        self::assertSame($source, $catalog->sourceOf($list));
        self::assertSame(1, $source->listings);
    }

    /**
     * @param string $name
     * @param string $title
     * @param AbilityAccess $access
     * @return AbilityInterface
     */
    private function ability(string $name, string $title, AbilityAccess $access): AbilityInterface
    {
        $factory = Bootstrap::getObjectManager()->get(AbilityInterfaceFactory::class);
        self::assertInstanceOf(AbilityInterfaceFactory::class, $factory);

        return $factory->create([
            'name' => $name,
            'title' => $title,
            'category' => 'Magento_Catalog',
            'source' => 'test',
            'sourceReference' => 'GET /V1/products',
            'access' => $access,
            'aclResources' => ['Magento_Catalog::products'],
        ]);
    }
}
