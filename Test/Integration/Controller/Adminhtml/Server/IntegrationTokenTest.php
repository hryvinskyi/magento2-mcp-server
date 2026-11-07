<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Controller\Adminhtml\Server;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Test\Integration\Fixture\CreatesIntegrations;
use Laminas\Http\Header\HeaderInterface;
use Magento\Backend\Model\Auth\StorageInterface;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Integration\Model\Integration;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Fetching an integration's access token through the real admin stack: the ACL pair, the form key, the readiness
 * judged again for the one integration, and an answer no cache keeps
 *
 * Each test dispatches once: the test application serves a second dispatch of one test from the first one's state.
 * No assertion prints a token: tokens are compared with hash_equals() and looked for with str_contains().
 */
#[DbIsolation(true)]
class IntegrationTokenTest extends AbstractBackendController
{
    use CreatesIntegrations;

    private const ACCESS = 'Hryvinskyi_McpServer::access';
    private const URI = 'backend/mcpserver/server/integrationtoken';

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::view';

    /**
     * @var string
     */
    protected $uri = self::URI;

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * Log in, and make the request the next page rather than the first page after login, which redirects
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $session = $this->_session;
        self::assertInstanceOf(StorageInterface::class, $session);
        $session->setIsFirstPageAfterLogin(false);
    }

    /**
     * An admin allowed the page and integration tokens gets a ready integration's token
     *
     * @return void
     */
    public function testAclHasAccess(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);

        $response = $this->post(['integration_id' => (string) $integration['id']]);

        self::assertSame(200, $response->getHttpResponseCode());
        self::assertTrue(hash_equals($integration['token'], $this->answer($response, 'token')), 'The token');
    }

    /**
     * An admin whose role may not view the page gets no token
     *
     * @return void
     */
    public function testAclNoAccess(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);
        $user = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $user);
        $this->acl()->deny($user->getRoles(), $this->resource);

        $response = $this->post(['integration_id' => (string) $integration['id']]);

        self::assertSame($this->expectedNoAccessResponseCode, $response->getHttpResponseCode());
        self::assertFalse(str_contains((string) $response->getBody(), $integration['token']), 'No token is sent');
    }

    public function testAReadyIntegrationsTokenIsAnsweredAndNeverCached(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);

        $response = $this->post(['integration_id' => (string) $integration['id']]);

        self::assertSame(200, $response->getHttpResponseCode());
        self::assertTrue(
            hash_equals($integration['token'], $this->answer($response, 'token')),
            'The answer is the integration\'s stored access token'
        );
        self::assertStringContainsString('no-store', $this->header($response, 'Cache-Control'));
        self::assertSame('no-cache', $this->header($response, 'Pragma'));
        self::assertSame('nosniff', $this->header($response, 'X-Content-Type-Options'));
        self::assertStringContainsString('application/json', $this->header($response, 'Content-Type'));
    }

    public function testAnIntegrationWithAllResourcesIsAnsweredItsToken(): void
    {
        $integration = $this->createIntegration('mcp-test all resources', null);

        $response = $this->post(['integration_id' => (string) $integration['id']]);

        self::assertSame(200, $response->getHttpResponseCode());
        self::assertTrue(hash_equals($integration['token'], $this->answer($response, 'token')), 'The token');
    }

    public function testAnInactiveIntegrationIsRefusedWithTheReasonAndWithoutItsToken(): void
    {
        $integration = $this->createIntegration('mcp-test inactive', [self::ACCESS], Integration::STATUS_INACTIVE);

        $this->assertRefused($integration, IntegrationReadiness::Inactive);
    }

    public function testAnIntegrationToBeReauthorisedIsRefusedWithTheReasonAndWithoutItsToken(): void
    {
        $integration = $this->createIntegration('mcp-test recreated', [self::ACCESS], Integration::STATUS_RECREATED);

        $this->assertRefused($integration, IntegrationReadiness::NeedsReauthorisation);
    }

    public function testAnIntegrationWithoutTheEndpointsResourceIsRefusedWithoutItsToken(): void
    {
        $integration = $this->createIntegration('mcp-test stores only', ['Magento_Backend::stores']);

        $this->assertRefused($integration, IntegrationReadiness::NoEndpointAccess);
    }

    public function testAnIntegrationWhoseTokenWasRevokedIsRefusedWithoutItsToken(): void
    {
        $integration = $this->createIntegration('mcp-test revoked', [self::ACCESS]);
        $this->revokeTokenOf($integration['consumerId']);

        $this->assertRefused($integration, IntegrationReadiness::NoToken);
    }

    public function testAnUnknownIntegrationIsNotFound(): void
    {
        $integration = $this->createIntegration('mcp-test known', [self::ACCESS]);

        $response = $this->post(['integration_id' => (string) ($integration['id'] + 1000)]);

        self::assertSame(404, $response->getHttpResponseCode());
        self::assertSame('This integration does not exist any more.', $this->answer($response, 'error'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badIds(): array
    {
        return [
            'empty' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'not a number' => ['abc'],
            'a fraction' => ['1.5'],
            'with a leading zero' => ['01'],
            'with a space' => [' 1'],
            'with a trailing line break' => ["1\n"],
        ];
    }

    /**
     * @param string $id
     * @return void
     */
    #[DataProvider('badIds')]
    public function testAnIdThatIsNotAPositiveWholeNumberIsABadRequest(string $id): void
    {
        $response = $this->post(['integration_id' => $id]);

        self::assertSame(400, $response->getHttpResponseCode());
        self::assertSame('Choose an integration.', $this->answer($response, 'error'));
    }

    public function testARequestWithoutAnIdIsABadRequest(): void
    {
        $response = $this->post([]);

        self::assertSame(400, $response->getHttpResponseCode());
    }

    public function testAGetIsNotServed(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setMethod(HttpRequest::METHOD_GET)->setParams(['integration_id' => (string) $integration['id']]);

        $this->dispatch(self::URI);

        $response = $this->response();
        self::assertNotSame(200, $response->getHttpResponseCode(), 'A GET is not answered');
        self::assertFalse(str_contains((string) $response->getBody(), $integration['token']), 'No token is sent');
    }

    /**
     * Showing integration tokens is a right of its own in Magento; without it the admin gets no token here either
     *
     * @return void
     */
    public function testAnAdminWhoMayNotSeeIntegrationTokensGetsNone(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);
        $user = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $user);
        $this->acl()->deny($user->getRoles(), 'Magento_Integration::integrations');
        self::assertTrue($this->acl()->isAllowed($user->getAclRole(), $this->resource), 'The page is still allowed');

        $response = $this->post(['integration_id' => (string) $integration['id']]);

        self::assertSame(403, $response->getHttpResponseCode());
        self::assertFalse(str_contains((string) $response->getBody(), $integration['token']), 'No token is sent');
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse(
            is_array($body) && is_string($body['error'] ?? null),
            'Magento answers a missing right with its own access-denied page, not with the endpoint\'s JSON refusal, '
            . 'so the page reads it as a failed fetch rather than as a refused integration'
        );
    }

    /**
     * The test framework adds a valid form key to a post that has none, so the check is proven with a wrong one
     *
     * @return void
     */
    public function testAPostWithAWrongFormKeyGetsNoToken(): void
    {
        $integration = $this->createIntegration('mcp-test endpoint only', [self::ACCESS]);

        $response = $this->post([
            'integration_id' => (string) $integration['id'],
            'form_key' => 'not-the-session-form-key',
        ]);

        self::assertNotSame(200, $response->getHttpResponseCode(), 'The request is not answered');
        self::assertTrue($response->isRedirect(), 'The admin is sent to the start page');
        self::assertFalse(str_contains((string) $response->getBody(), $integration['token']), 'No token is sent');
    }

    /**
     * Assert that the integration's token is refused with 409, the readiness reason, and without the token
     *
     * @param array{id: int, consumerId: int, token: string} $integration
     * @param IntegrationReadiness $readiness
     * @return void
     */
    private function assertRefused(array $integration, IntegrationReadiness $readiness): void
    {
        $response = $this->post(['integration_id' => (string) $integration['id']]);

        self::assertTrue($integration['token'] !== '', 'The integration has a token that could have leaked');
        self::assertFalse(str_contains((string) $response->getBody(), $integration['token']), 'No token is sent');
        self::assertSame(409, $response->getHttpResponseCode());
        self::assertSame($readiness->reason()->render(), $this->answer($response, 'error'));
        self::assertStringContainsString('no-store', $this->header($response, 'Cache-Control'));
    }

    /**
     * Dispatch a form post with these values
     *
     * @param array<string, string> $values
     * @return Response
     */
    private function post(array $values): Response
    {
        $request = $this->getRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->setMethod(HttpRequest::METHOD_POST)->setParams($values)->setPostValue($values);

        $this->dispatch(self::URI);

        return $this->response();
    }

    /**
     * @return Response
     */
    private function response(): Response
    {
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * One string field of the JSON answer
     *
     * @param Response $response
     * @param string $field
     * @return string
     */
    private function answer(Response $response, string $field): string
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body, 'The answer is a JSON object');
        self::assertSame([$field], array_keys($body), 'The answer holds only ' . $field);
        $value = $body[$field];
        self::assertIsString($value);

        return $value;
    }

    /**
     * A header's value
     *
     * @param Response $response
     * @param string $name
     * @return string
     */
    private function header(Response $response, string $name): string
    {
        $header = $response->getHeader($name);
        self::assertInstanceOf(HeaderInterface::class, $header, $name . ' is sent once');

        return $header->getFieldValue();
    }

    /**
     * The ACL the request is authorised against
     *
     * @return \Magento\Framework\Acl
     */
    private function acl(): \Magento\Framework\Acl
    {
        $builder = $this->_objectManager->get(AclBuilder::class);
        self::assertInstanceOf(AclBuilder::class, $builder);

        return $builder->getAcl();
    }
}
