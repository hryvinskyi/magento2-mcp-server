<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Data;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;

/**
 * Immutable ability, created through AbilityInterfaceFactory
 *
 * The constructor refuses a name that breaks the ability-name format and a blank title, category, source or source
 * reference, so an ability that exists is always one a client can be shown and call by name.
 */
class Ability implements AbilityInterface
{
    /**
     * @param string $name
     * @param string $title
     * @param string $category
     * @param string $source
     * @param string $sourceReference
     * @param AbilityAccess $access
     * @param list<string> $aclResources
     * @param string $description
     * @throws \InvalidArgumentException When a value breaks the rules of an ability
     */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly string $category,
        private readonly string $source,
        private readonly string $sourceReference,
        private readonly AbilityAccess $access,
        private readonly array $aclResources = [],
        private readonly string $description = ''
    ) {
        if (strlen($name) > self::NAME_MAX_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(
                sprintf(
                    'The ability name "%s" is not `<module>.<service>.<method>` in lowercase words joined by "_", '
                    . 'at most %d characters long.',
                    $name,
                    self::NAME_MAX_LENGTH
                )
            );
        }

        $this->assertNotBlank('title', $title);
        $this->assertNotBlank('category', $category);
        $this->assertNotBlank('source', $source);
        $this->assertNotBlank('source reference', $sourceReference);

        foreach ($aclResources as $resource) {
            if (trim($resource) === '') {
                throw new \InvalidArgumentException(
                    sprintf('The ability "%s" lists a blank ACL resource.', $name)
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheritDoc
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * @inheritDoc
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @inheritDoc
     */
    public function getSourceReference(): string
    {
        return $this->sourceReference;
    }

    /**
     * @inheritDoc
     */
    public function getAccess(): AbilityAccess
    {
        return $this->access;
    }

    /**
     * @inheritDoc
     */
    public function getAclResources(): array
    {
        return $this->aclResources;
    }

    /**
     * Refuse a value that is empty or only whitespace
     *
     * @param string $field
     * @param string $value
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertNotBlank(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(
                sprintf('The ability "%s" needs a %s.', $this->name, $field)
            );
        }
    }
}
