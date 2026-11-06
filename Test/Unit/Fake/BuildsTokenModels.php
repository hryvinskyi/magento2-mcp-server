<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Fake;

use Magento\Integration\Model\Oauth\Token;

/**
 * OAuth access token models holding the given data, built without their database collaborators
 *
 * For a test case: the model is a double with no method replaced, so its magic getters read the data set here.
 */
trait BuildsTokenModels
{
    /**
     * An access token model with this value and revocation flag
     *
     * @param string $value
     * @param int $revoked
     * @return Token
     */
    private function tokenModel(string $value, int $revoked): Token
    {
        $token = $this->getMockBuilder(Token::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $token->setData(['token' => $value, 'revoked' => $revoked, 'type' => Token::TYPE_ACCESS]);

        return $token;
    }
}
