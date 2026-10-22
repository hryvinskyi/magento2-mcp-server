<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Tool;

use Hryvinskyi\McpServer\Api\AbilityCatalogInterface;
use Hryvinskyi\McpServer\Api\ExposurePolicyInterface;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * `get_ability_info`: everything a client needs to call one ability, including whether the caller's role may
 *
 * An ability is described whatever the caller's ACL, so a caller learns which resources its role lacks. An ability no
 * client may call says why (`denialReason`: `deny_list`, `read_only_mode`, `anonymous_ability` or another rule's
 * code); one a client may call has no reason. A name that is no ability is a tool error the model can correct.
 */
class GetAbilityInfo implements ToolInterface
{
    public const NAME = 'get_ability_info';

    /**
     * @param AbilityCatalogInterface $catalog
     * @param ExposurePolicyInterface $exposurePolicy
     * @param ArgumentReader $arguments
     * @param ToolResults $toolResults
     */
    public function __construct(
        private readonly AbilityCatalogInterface $catalog,
        private readonly ExposurePolicyInterface $exposurePolicy,
        private readonly ArgumentReader $arguments,
        private readonly ToolResults $toolResults
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Describe one ability: what it does, the JSON Schema of the arguments execute_ability passes to it, '
            . 'the ACL resources it needs, whether your role has them (allowedForYou) and its exposure in this store, '
            . 'with the reason calls are refused (denialReason) when they are. Read it before calling execute_ability.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The ability name, as discover_abilities lists it.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        return [
            'title' => 'Get ability info',
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function call(array $arguments, CallContext $context): array
    {
        $name = $this->arguments->requiredString($arguments, 'name');
        try {
            $ability = $this->catalog->get($name);
        } catch (NoSuchEntityException) {
            return $this->toolResults->unknownAbility($name);
        }

        $decision = $this->exposurePolicy->decisionOf($ability);
        $info = [
            'name' => $ability->getName(),
            'title' => $ability->getTitle(),
            'description' => $ability->getDescription(),
            'category' => $ability->getCategory(),
            'source' => $ability->getSource(),
            'access' => $ability->getAccess()->value,
            'aclResources' => $ability->getAclResources(),
            'exposure' => $decision->getExposure()->value,
            'allowedForYou' => $this->catalog->isAllowed($ability),
            'inputSchema' => $this->catalog->schemaOf($ability),
        ];
        if ($decision->getDenialReason() !== '') {
            $info['denialReason'] = $decision->getDenialReason();
        }

        return $this->toolResults->success($info);
    }
}
