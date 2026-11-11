<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Instruction;

use Hryvinskyi\McpServer\Api\PersonalDataPolicyInterface;

/**
 * While the store hides personal data, says so, so the model reads the placeholder as a choice of the store rather than
 * as missing data
 */
class PersonalDataInstruction implements InstructionInterface
{
    /**
     * @param PersonalDataPolicyInterface $policy
     */
    public function __construct(
        private readonly PersonalDataPolicyInterface $policy
    ) {
    }

    /**
     * @inheritDoc
     */
    public function text(): string
    {
        if (!$this->policy->isHidingOn()) {
            return '';
        }

        return 'This store keeps personal data from clients: names, email addresses, phone numbers, addresses and the '
            . 'like arrive as "' . PersonalDataPolicyInterface::HIDDEN . '". Do not ask for them and do not guess '
            . 'them; get_ability_info tells which abilities hide them (personalData).';
    }
}
