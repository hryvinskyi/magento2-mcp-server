<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\ViewModel;

use Hryvinskyi\McpServer\Model\Admin\Integration\IntegrationDirectoryInterface;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Integration\Model\Config\AuthorizationConfig;

/**
 * What the connection card of the MCP Server page shows about the store's integrations and their tokens
 *
 * - Only an admin who may see integration tokens on Magento's own integration page is offered the integrations to
 *   pick from, the link to create one and the script that fetches a picked integration's token. Everyone else sees
 *   nothing of it and pastes a token by hand.
 * - The page lists each integration's id, name and readiness, never a token: the token is fetched from its own admin
 *   route when the admin picks a ready integration, and that route checks the readiness again.
 * - Whether Magento accepts integration tokens as bearer tokens is shown to everyone; the link to the setting only to
 *   an admin who may open it.
 *
 * Listing the integrations rebuilds the ACL once per integration, so the list is read at most once per page.
 *
 * @phpstan-type IntegrationOption array{id: int, name: string, ready: bool, reason: string, editUrl: string}
 */
class IntegrationAccess implements ArgumentInterface
{
    private const INTEGRATIONS_RESOURCE = 'Magento_Integration::integrations';
    private const OAUTH_CONFIG_RESOURCE = 'Magento_Integration::config_oauth';
    private const OAUTH_CONFIG_SECTION = 'oauth';
    private const TOKEN_ROUTE = 'hryvinskyi_mcp/server/integrationtoken';
    private const PICKER_COMPONENT = 'Hryvinskyi_McpServer/js/integration-picker';

    /**
     * The integrations as the page lists them, once read
     *
     * @var list<IntegrationOption>|null
     */
    private ?array $options = null;

    /**
     * @param IntegrationDirectoryInterface $directory
     * @param AuthorizationInterface $authorization
     * @param BackendUrl $backendUrl
     * @param FormKey $formKey
     * @param AuthorizationConfig $bearerConfig
     * @param JsonHexTag $json
     */
    public function __construct(
        private readonly IntegrationDirectoryInterface $directory,
        private readonly AuthorizationInterface $authorization,
        private readonly BackendUrl $backendUrl,
        private readonly FormKey $formKey,
        private readonly AuthorizationConfig $bearerConfig,
        private readonly JsonHexTag $json
    ) {
    }

    /**
     * Whether the admin may pick an integration, which takes the right to see integration tokens
     *
     * @return bool
     */
    public function canPick(): bool
    {
        return $this->authorization->isAllowed(self::INTEGRATIONS_RESOURCE);
    }

    /**
     * Every integration of the store, whether its token can be used and where to edit it
     *
     * None when the admin may not pick. The reason is the sentence the admin reads about the integration's state.
     * The list is read from the store once and kept for the rest of the request.
     *
     * @return list<IntegrationOption>
     */
    public function integrations(): array
    {
        if (!$this->canPick()) {
            return [];
        }
        if ($this->options === null) {
            $this->options = array_map(
                fn (array $row): array => [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'ready' => $row['readiness']->isReady(),
                    'reason' => (string) $row['readiness']->reason(),
                    'editUrl' => $this->backendUrl->getUrl('adminhtml/integration/edit', ['id' => $row['id']]),
                ],
                $this->directory->all()
            );
        }

        return $this->options;
    }

    /**
     * Magento's form for a new integration, or null when the admin may not pick
     *
     * @return string|null
     */
    public function newIntegrationUrl(): ?string
    {
        return $this->canPick() ? $this->backendUrl->getUrl('adminhtml/integration/new') : null;
    }

    /**
     * Whether Magento accepts an integration's access token as a bearer token, as MCP clients send it
     *
     * @return bool
     */
    public function tokensAcceptedAsBearer(): bool
    {
        return $this->bearerConfig->isIntegrationAsBearerEnabled();
    }

    /**
     * The OAuth settings, where integration tokens are allowed as bearer tokens; null unless the admin may open them
     *
     * @return string|null
     */
    public function bearerSettingsUrl(): ?string
    {
        return $this->authorization->isAllowed(self::OAUTH_CONFIG_RESOURCE)
            ? $this->backendUrl->getUrl('adminhtml/system_config/edit', ['section' => self::OAUTH_CONFIG_SECTION])
            : null;
    }

    /**
     * The x-magento-init JSON that mounts the integration picker on the element the selector finds
     *
     * Empty when the admin may not pick, and the template then prints nothing. The script receives where to fetch a
     * picked integration's token, the form key the admin route requires on a POST, and the wording of its status
     * line. No token is in this JSON. When the token cannot be fetched, the likely cause is an expired admin session
     * or form key, which only reloading the page renews. Tags are hex-escaped, so the JSON cannot end its script
     * element.
     *
     * @param string $selector
     * @return string
     * @throws LocalizedException when the session's form key cannot be read
     */
    public function mountConfig(string $selector): string
    {
        if (!$this->canPick()) {
            return '';
        }

        return (string) $this->json->serialize([
            $selector => [self::PICKER_COMPONENT => [
                'tokenUrl' => $this->backendUrl->getUrl(self::TOKEN_ROUTE),
                'formKey' => $this->formKey->getFormKey(),
                'wording' => [
                    'loading' => (string) __('Getting the token…'),
                    'refused' => (string) __('This integration\'s token cannot be used.'),
                    'failed' => (string) __(
                        'The token could not be fetched. Reload the page and pick again, or paste it by hand.'
                    ),
                ],
            ]],
        ]);
    }
}
