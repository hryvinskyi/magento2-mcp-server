<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Fixture;

use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Integration\Model\Oauth\Token;
use Magento\Integration\Model\ResourceModel\Integration as IntegrationResource;
use Magento\Integration\Model\ResourceModel\Oauth\Token as TokenResource;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Assert;

/**
 * Real integrations, made the way an admin makes them in System › Extensions › Integrations, for a test case
 *
 * An integration is created through Magento's integration service, which grants it the resources given, then set to
 * the status asked for (a created integration is inactive) and given an access token as activating it would. Use it
 * in a DB-isolated test so everything is rolled back. Never print a token: compare it with hash_equals().
 */
trait CreatesIntegrations
{
    /**
     * Create an integration and return its id, its consumer's id and its access token ('' when it has none)
     *
     * @param string $name
     * @param list<string>|null $resources The resources it is granted; null grants all resources
     * @param int $status One of Magento's integration status codes
     * @param bool $withToken Whether it gets an access token
     * @return array{id: int, consumerId: int, token: string}
     */
    private function createIntegration(
        string $name,
        ?array $resources,
        int $status = Integration::STATUS_ACTIVE,
        bool $withToken = true
    ): array {
        $objectManager = Bootstrap::getObjectManager();
        $service = $objectManager->get(IntegrationServiceInterface::class);
        Assert::assertInstanceOf(IntegrationServiceInterface::class, $service);
        $data = ['name' => $name, 'setup_type' => Integration::TYPE_MANUAL];
        $data += $resources === null ? ['all_resources' => 1] : ['resource' => $resources];
        $integration = $service->create($data);
        Assert::assertInstanceOf(Integration::class, $integration);

        $integration->setData(Integration::STATUS, $status);
        $integrationResource = $objectManager->get(IntegrationResource::class);
        Assert::assertInstanceOf(IntegrationResource::class, $integrationResource);
        $integrationResource->save($integration);

        $id = $integration->getId();
        $consumerId = $integration->getData(Integration::CONSUMER_ID);
        Assert::assertIsNumeric($id);
        Assert::assertIsNumeric($consumerId);
        $token = '';
        if ($withToken) {
            $oauth = $this->oauthService();
            Assert::assertTrue($oauth->createAccessToken((int) $consumerId, true), 'An access token was created');
            $token = $this->accessTokenModel((int) $consumerId)->getData('token');
            Assert::assertIsString($token);
        }
        AdminRoles::forgetAcl();

        return ['id' => (int) $id, 'consumerId' => (int) $consumerId, 'token' => $token];
    }

    /**
     * Revoke the access token of the integration's consumer, as revoking it in the database does
     *
     * @param int $consumerId
     * @return void
     */
    private function revokeTokenOf(int $consumerId): void
    {
        $token = $this->accessTokenModel($consumerId);
        $token->setRevoked(1);
        $tokenResource = Bootstrap::getObjectManager()->get(TokenResource::class);
        Assert::assertInstanceOf(TokenResource::class, $tokenResource);
        $tokenResource->save($token);
    }

    /**
     * The access token model of the consumer, which must have one
     *
     * @param int $consumerId
     * @return Token
     */
    private function accessTokenModel(int $consumerId): Token
    {
        $token = $this->oauthService()->getAccessToken($consumerId);
        Assert::assertInstanceOf(Token::class, $token, 'The consumer has an access token');

        return $token;
    }

    /**
     * @return OauthServiceInterface
     */
    private function oauthService(): OauthServiceInterface
    {
        $oauth = Bootstrap::getObjectManager()->get(OauthServiceInterface::class);
        Assert::assertInstanceOf(OauthServiceInterface::class, $oauth);

        return $oauth;
    }
}
