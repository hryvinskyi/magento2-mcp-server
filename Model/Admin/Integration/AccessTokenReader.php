<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Oauth\Token;

/**
 * Reads a consumer's access token through Magento's OAuth service and keeps it only when the endpoint would accept it
 *
 * The OAuth service returns the stored access token whether or not it was revoked, so the revocation is checked here.
 */
class AccessTokenReader implements AccessTokenReaderInterface
{
    /**
     * @param OauthServiceInterface $oauthService
     */
    public function __construct(
        private readonly OauthServiceInterface $oauthService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function usableTokenOf(int $consumerId): ?string
    {
        $token = $this->oauthService->getAccessToken($consumerId);
        if (!$token instanceof Token || (bool) $token->getRevoked()) {
            return null;
        }
        $value = $token->getData('token');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
