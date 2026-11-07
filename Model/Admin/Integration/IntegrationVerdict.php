<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin\Integration;

/**
 * The readiness of one integration as judged at one moment, with the usable access token found while judging it
 *
 * A ready integration always carries its token and one that is not ready never carries any: the readiness "ready"
 * is only reached by reading a usable token, so the token that decided it travels with it. Whoever hands the token
 * out therefore reads neither the integration nor its token a second time, and cannot hand out a token the judgement
 * did not see. Created through IntegrationVerdictFactory.
 */
class IntegrationVerdict
{
    /**
     * @param IntegrationReadiness $readiness
     * @param string|null $usableToken The access token, given exactly when the integration is ready
     * @throws \InvalidArgumentException When a ready integration has no token, one that is not ready has a token, or
     *     the token is empty
     */
    public function __construct(
        private readonly IntegrationReadiness $readiness,
        #[\SensitiveParameter] private readonly ?string $usableToken = null
    ) {
        if ($usableToken === '') {
            throw new \InvalidArgumentException('A usable access token is not empty.');
        }
        if ($readiness->isReady() !== ($usableToken !== null)) {
            throw new \InvalidArgumentException(sprintf(
                'A %s integration %s an access token.',
                $readiness->value,
                $readiness->isReady() ? 'needs' : 'carries no'
            ));
        }
    }

    /**
     * Whether the integration can be used, and if not, why
     *
     * @return IntegrationReadiness
     */
    public function getReadiness(): IntegrationReadiness
    {
        return $this->readiness;
    }

    /**
     * The access token of a ready integration; null for one that is not ready
     *
     * @return string|null
     */
    public function getUsableToken(): ?string
    {
        return $this->usableToken;
    }
}
