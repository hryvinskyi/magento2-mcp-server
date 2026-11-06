<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

use Magento\Framework\Phrase;

/**
 * Whether an integration's access token can be handed to an MCP client, and if not, why
 *
 * - ready: the integration is active, its ACL allows the MCP endpoint, and it has an access token that is not revoked;
 * - inactive: the integration was never activated;
 * - needs-reauthorisation: Magento asks the admin to activate the integration again (its configuration changed). Its
 *   old token still authenticates, but the integration is not ready until the admin re-authorises it;
 * - no-endpoint-access: the integration's resources include neither the MCP endpoint's resource nor all resources,
 *   or the integration has no ACL role at all;
 * - no-token: the integration is active and allowed, but has no access token, or only a revoked one.
 *
 * When several apply, the first wins in this order: not active (never activated, or to be re-authorised), no endpoint
 * access, no token. The ACL of an integration that is not active is irrelevant to its readiness.
 *
 * How this compares with the MCP endpoint itself: the endpoint refuses a revoked token and an integration whose ACL
 * denies the MCP endpoint's resource, exactly like the states above. It does not look at the integration's status:
 * a bearer token is accepted whenever integration tokens are allowed as bearer tokens. Refusing an integration that
 * is not active is therefore deliberately stricter than the endpoint: no token is handed out for an integration
 * Magento itself shows as not activated or to be re-authorised.
 */
enum IntegrationReadiness: string
{
    case Ready = 'ready';
    case Inactive = 'inactive';
    case NeedsReauthorisation = 'needs-reauthorisation';
    case NoEndpointAccess = 'no-endpoint-access';
    case NoToken = 'no-token';

    /**
     * Whether the integration's token can be handed to an MCP client
     *
     * @return bool
     */
    public function isReady(): bool
    {
        return $this === self::Ready;
    }

    /**
     * The sentence the admin reads about this state: why the integration cannot be used, or that it can
     *
     * @return Phrase
     */
    public function reason(): Phrase
    {
        return match ($this) {
            self::Ready => __('Ready'),
            self::Inactive => __('Not activated yet. Activate it in System › Extensions › Integrations.'),
            self::NeedsReauthorisation
                => __('Its settings changed. Reauthorize it in System › Extensions › Integrations.'),
            self::NoEndpointAccess
                => __('Its API resources leave out "Use the MCP endpoint". Allow that resource, or all resources.'),
            self::NoToken
                => __('It has no usable access token. Reauthorize it in System › Extensions › Integrations.'),
        };
    }
}
