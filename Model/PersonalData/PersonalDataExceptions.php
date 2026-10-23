<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\PersonalData;

use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\PersonalDataExceptionsInterface;
use Hryvinskyi\McpServer\Model\ResourceModel\NameSet;
use Magento\Framework\Exception\CouldNotSaveException;

/**
 * The abilities shown personal data, kept in the table hryvinskyi_mcp_personal_data_shown
 */
class PersonalDataExceptions implements PersonalDataExceptionsInterface
{
    /**
     * @param NameSet $shown
     */
    public function __construct(
        private readonly NameSet $shown
    ) {
    }

    /**
     * @inheritDoc
     */
    public function shown(): array
    {
        return $this->shown->all();
    }

    /**
     * @inheritDoc
     */
    public function show(array $abilities): void
    {
        foreach ($abilities as $ability) {
            if (mb_strlen($ability) > AbilityInterface::NAME_MAX_LENGTH
                || preg_match(AbilityInterface::NAME_PATTERN, $ability) !== 1
            ) {
                throw new CouldNotSaveException(__('"%1" is not an ability name.', $ability));
            }
        }

        try {
            $this->shown->add($abilities);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('The abilities shown personal data could not be saved.'), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function hide(array $abilities): void
    {
        try {
            $this->shown->remove($abilities);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('The abilities shown personal data could not be saved.'), $e);
        }
    }
}
