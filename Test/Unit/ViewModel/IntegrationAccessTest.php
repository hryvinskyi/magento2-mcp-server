<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Unit\ViewModel;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationDirectoryInterface;
use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationReadiness;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAuthorization;
use Hryvinskyi\McpServer\ViewModel\IntegrationAccess;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Integration\Model\Config\AuthorizationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only an admin with the right to see integration tokens is offered the integrations, the link to a new one and the
 * picker script; the bearer setting is shown to everyone, its settings link only with the right to open them
 */
class IntegrationAccessTest extends TestCase
{
    private const INTEGRATIONS = 'Magento_Integration::integrations';
    private const OAUTH_CONFIG = 'Magento_Integration::config_oauth';
    private const SELECTOR = '[data-role="mcp-connection-card"]';
    private const FORM_KEY = 'f0rmK3yV4lue1234';

    public function testWithoutTheIntegrationsRightNothingCanBePicked(): void
    {
        self::assertFalse($this->access([], $this->untouchedDirectory())->canPick());
    }

    public function testWithTheIntegrationsRightIntegrationsCanBePicked(): void
    {
        self::assertTrue($this->access([self::INTEGRATIONS], $this->untouchedDirectory())->canPick());
    }

    public function testWithoutTheIntegrationsRightNoIntegrationIsListedAndTheDirectoryIsNotRead(): void
    {
        self::assertSame([], $this->access([self::OAUTH_CONFIG], $this->untouchedDirectory())->integrations());
    }

    public function testWithoutTheIntegrationsRightThereIsNoLinkToANewIntegration(): void
    {
        self::assertNull($this->access([self::OAUTH_CONFIG], $this->untouchedDirectory())->newIntegrationUrl());
    }

    public function testWithoutTheIntegrationsRightNoPickerIsMounted(): void
    {
        /** @var FormKey&MockObject $formKey */
        $formKey = $this->createMock(FormKey::class);
        $formKey->expects(self::never())->method('getFormKey');

        $access = $this->access([self::OAUTH_CONFIG], $this->untouchedDirectory(), formKey: $formKey);

        self::assertSame('', $access->mountConfig(self::SELECTOR));
    }

    public function testEachIntegrationIsListedWithItsReadinessReasonAndEditLink(): void
    {
        /** @var IntegrationDirectoryInterface&MockObject $directory */
        $directory = $this->createMock(IntegrationDirectoryInterface::class);
        $directory->method('all')->willReturn([
            ['id' => 3, 'name' => 'Claude', 'readiness' => IntegrationReadiness::Ready],
            ['id' => 7, 'name' => 'Old ERP', 'readiness' => IntegrationReadiness::NoEndpointAccess],
        ]);

        self::assertSame(
            [
                [
                    'id' => 3,
                    'name' => 'Claude',
                    'ready' => true,
                    'reason' => (string) IntegrationReadiness::Ready->reason(),
                    'editUrl' => 'https://admin.example/adminhtml/integration/edit?id=3',
                ],
                [
                    'id' => 7,
                    'name' => 'Old ERP',
                    'ready' => false,
                    'reason' => (string) IntegrationReadiness::NoEndpointAccess->reason(),
                    'editUrl' => 'https://admin.example/adminhtml/integration/edit?id=7',
                ],
            ],
            $this->access([self::INTEGRATIONS], $directory)->integrations()
        );
    }

    public function testTheIntegrationsAreReadFromTheDirectoryOncePerRequest(): void
    {
        /** @var IntegrationDirectoryInterface&MockObject $directory */
        $directory = $this->createMock(IntegrationDirectoryInterface::class);
        $directory->expects(self::once())->method('all')->willReturn([
            ['id' => 3, 'name' => 'Claude', 'readiness' => IntegrationReadiness::Ready],
        ]);
        $access = $this->access([self::INTEGRATIONS], $directory);

        $first = $access->integrations();

        self::assertSame($first, $access->integrations());
    }

    public function testAStoreWithoutIntegrationsListsNone(): void
    {
        /** @var IntegrationDirectoryInterface&MockObject $directory */
        $directory = $this->createMock(IntegrationDirectoryInterface::class);
        $directory->method('all')->willReturn([]);

        self::assertSame([], $this->access([self::INTEGRATIONS], $directory)->integrations());
    }

    public function testWithTheIntegrationsRightTheNewIntegrationLinkIsMagentosOwnForm(): void
    {
        self::assertSame(
            'https://admin.example/adminhtml/integration/new',
            $this->access([self::INTEGRATIONS], $this->untouchedDirectory())->newIntegrationUrl()
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function bearerSettings(): array
    {
        return ['accepted' => [true], 'not accepted' => [false]];
    }

    #[DataProvider('bearerSettings')]
    public function testWhetherTokensAreAcceptedAsBearerIsMagentosSetting(bool $accepted): void
    {
        /** @var AuthorizationConfig&MockObject $bearerConfig */
        $bearerConfig = $this->createMock(AuthorizationConfig::class);
        $bearerConfig->method('isIntegrationAsBearerEnabled')->willReturn($accepted);

        $access = $this->access([], $this->untouchedDirectory(), bearerConfig: $bearerConfig);

        self::assertSame($accepted, $access->tokensAcceptedAsBearer());
    }

    public function testWithTheOauthSettingsRightTheSettingsLinkOpensTheOauthSection(): void
    {
        self::assertSame(
            'https://admin.example/adminhtml/system_config/edit?section=oauth',
            $this->access([self::OAUTH_CONFIG], $this->untouchedDirectory())->bearerSettingsUrl()
        );
    }

    public function testWithoutTheOauthSettingsRightThereIsNoSettingsLinkEvenWithTheIntegrationsRight(): void
    {
        self::assertNull($this->access([self::INTEGRATIONS], $this->untouchedDirectory())->bearerSettingsUrl());
    }

    public function testThePickerIsMountedWithTheTokenRouteTheFormKeyAndItsWordingOnly(): void
    {
        $config = json_decode(
            $this->access([self::INTEGRATIONS], $this->untouchedDirectory())->mountConfig(self::SELECTOR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame(
            [
                self::SELECTOR => [
                    'Hryvinskyi_McpServer/js/integration-picker' => [
                        'tokenUrl' => 'https://admin.example/hryvinskyi_mcp/server/integrationtoken',
                        'formKey' => self::FORM_KEY,
                        'wording' => [
                            'loading' => 'Getting the token…',
                            'refused' => 'This integration\'s token cannot be used.',
                            'failed' => 'The token could not be fetched. Reload the page and pick again, '
                                . 'or paste it by hand.',
                        ],
                    ],
                ],
            ],
            $config
        );
    }

    public function testTheMountConfigCannotEndItsScriptElement(): void
    {
        $json = $this->access([self::INTEGRATIONS], $this->untouchedDirectory())
            ->mountConfig('</script><script>alert(1)</script>');

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
    }

    /**
     * A directory the test expects never to be read
     *
     * @return IntegrationDirectoryInterface
     */
    private function untouchedDirectory(): IntegrationDirectoryInterface
    {
        /** @var IntegrationDirectoryInterface&MockObject $directory */
        $directory = $this->createMock(IntegrationDirectoryInterface::class);
        $directory->expects(self::never())->method('all');
        $directory->expects(self::never())->method('readinessOf');

        return $directory;
    }

    /**
     * The view model for an admin allowed the given resources, with a URL builder that writes the route and its
     * parameters as a query string
     *
     * @param list<string> $allowed
     * @param IntegrationDirectoryInterface $directory
     * @param FormKey|null $formKey
     * @param AuthorizationConfig|null $bearerConfig
     * @return IntegrationAccess
     */
    private function access(
        array $allowed,
        IntegrationDirectoryInterface $directory,
        ?FormKey $formKey = null,
        ?AuthorizationConfig $bearerConfig = null
    ): IntegrationAccess {
        /** @var BackendUrl&MockObject $backendUrl */
        $backendUrl = $this->createMock(BackendUrl::class);
        $backendUrl->method('getUrl')->willReturnCallback(
            static function (?string $routePath = null, ?array $routeParams = null): string {
                $query = $routeParams === null || $routeParams === [] ? '' : '?' . http_build_query($routeParams);

                return 'https://admin.example/' . $routePath . $query;
            }
        );
        if ($formKey === null) {
            /** @var FormKey&MockObject $formKey */
            $formKey = $this->createMock(FormKey::class);
            $formKey->method('getFormKey')->willReturn(self::FORM_KEY);
        }

        return new IntegrationAccess(
            $directory,
            new FakeAuthorization($allowed),
            $backendUrl,
            $formKey,
            $bearerConfig ?? $this->createMock(AuthorizationConfig::class),
            new JsonHexTag()
        );
    }
}
