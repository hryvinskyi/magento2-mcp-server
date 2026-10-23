<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Model\Admin\AbilityRows;
use Hryvinskyi\McpServer\Model\ResourceModel\NameSet;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\Before;

/**
 * Before each test, forget what the shared name sets and catalog rows read: the last test's rows were rolled back
 * under them
 */
trait ForgetsStoredNames
{
    /**
     * @return void
     */
    #[Before]
    protected function forgetStoredNames(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        foreach (['HryvinskyiMcpServerPersonalDataShownNames', 'HryvinskyiMcpServerSectionOffNames'] as $set) {
            $names = $objectManager->get($set);
            self::assertInstanceOf(NameSet::class, $names);
            $names->_resetState();
        }
        $rows = $objectManager->get(AbilityRows::class);
        self::assertInstanceOf(AbilityRows::class, $rows);
        $rows->_resetState();
    }
}
