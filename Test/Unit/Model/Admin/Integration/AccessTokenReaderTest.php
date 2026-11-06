<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\Model\Admin\Integration;

use Hryvinskyi\McpServer\Model\Admin\Integration\AccessTokenReader;
use Hryvinskyi\McpServer\Test\Unit\Fake\BuildsTokenModels;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Oauth\Token;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A consumer's access token is usable when one exists, it is not empty and it is not revoked
 */
class AccessTokenReaderTest extends TestCase
{
    use BuildsTokenModels;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    public function testAnAccessTokenThatIsNotRevokedIsUsable(): void
    {
        $token = $this->reader($this->tokenModel(self::TOKEN, 0), 12)->usableTokenOf(12);

        self::assertTrue($token !== null && hash_equals(self::TOKEN, $token), 'The stored token is returned');
    }

    public function testARevokedTokenIsNone(): void
    {
        self::assertNull($this->reader($this->tokenModel(self::TOKEN, 1), 12)->usableTokenOf(12));
    }

    public function testAnEmptyTokenIsNone(): void
    {
        self::assertNull($this->reader($this->tokenModel('', 0), 12)->usableTokenOf(12));
    }

    public function testNoAccessTokenIsNone(): void
    {
        self::assertNull($this->reader(false, 12)->usableTokenOf(12));
    }

    /**
     * A reader over an OAuth service that answers this for the consumer
     *
     * @param Token|false $answer
     * @param int $consumerId
     * @return AccessTokenReader
     */
    private function reader(Token|false $answer, int $consumerId): AccessTokenReader
    {
        /** @var OauthServiceInterface&MockObject $oauth */
        $oauth = $this->createMock(OauthServiceInterface::class);
        $oauth->expects(self::once())->method('getAccessToken')->with($consumerId)->willReturn($answer);

        return new AccessTokenReader($oauth);
    }
}
