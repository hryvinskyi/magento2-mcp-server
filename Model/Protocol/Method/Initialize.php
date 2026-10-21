<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Protocol\Method;

use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Model\Protocol\CallContext;
use Hryvinskyi\McpServer\Model\Protocol\Message;
use Hryvinskyi\McpServer\Model\Protocol\ProtocolVersions;
use Hryvinskyi\McpServer\Model\Protocol\Reply;
use Hryvinskyi\McpServer\Model\Protocol\ReplyFactory;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;
use Magento\Framework\Module\PackageInfo;

/**
 * `initialize`: negotiates the protocol version and opens a session for the caller and its client
 *
 * The version is the one the client asked for when the server speaks it, else the newest the server speaks. The
 * client's name and version are what it gave in `clientInfo`, cut to the stored length; a client that gives none is
 * still served. Only a request opens a session: `initialize` sent as a notification is never handed to this
 * handler.
 */
class Initialize implements MethodHandlerInterface
{
    private const MODULE = 'Hryvinskyi_McpServer';
    private const SERVER_NAME = 'Magento MCP Server';
    private const INSTRUCTIONS = 'Find the operations of this Magento store with discover_abilities, then read the '
        . 'arguments of the one you need with get_ability_info. Run it with execute_ability, which the store may '
        . 'deny or ask you to confirm.';

    /**
     * @param SessionStoreInterface $sessions
     * @param ProtocolVersions $versions
     * @param ClientInterfaceFactory $clientFactory
     * @param PackageInfo $packageInfo
     * @param ReplyFactory $replyFactory
     */
    public function __construct(
        private readonly SessionStoreInterface $sessions,
        private readonly ProtocolVersions $versions,
        private readonly ClientInterfaceFactory $clientFactory,
        private readonly PackageInfo $packageInfo,
        private readonly ReplyFactory $replyFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(Message $message, CallContext $context): Reply
    {
        $params = $message->getParams();
        $requested = $params['protocolVersion'] ?? null;
        $version = $this->versions->negotiate(is_string($requested) ? $requested : '');
        $session = $this->sessions->create(
            $context->getCaller(),
            $this->client($params['clientInfo'] ?? null),
            $version
        );

        return $this->replyFactory->create(['sessionId' => $session->getId()])->withResult([
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => $this->packageInfo->getVersion(self::MODULE),
            ],
            'instructions' => self::INSTRUCTIONS,
        ]);
    }

    /**
     * The client as it introduced itself in `clientInfo`
     *
     * @param mixed $clientInfo
     * @return ClientInterface
     */
    private function client(mixed $clientInfo): ClientInterface
    {
        $info = is_array($clientInfo) ? $clientInfo : [];

        return $this->clientFactory->create([
            'name' => $this->text($info['name'] ?? null, ClientInterface::NAME_MAX_LENGTH),
            'version' => $this->text($info['version'] ?? null, ClientInterface::VERSION_MAX_LENGTH),
        ]);
    }

    /**
     * A string the client gave, cut to the length that is kept; '' for anything that is not a string
     *
     * @param mixed $value
     * @param int $maxLength
     * @return string
     */
    private function text(mixed $value, int $maxLength): string
    {
        return is_string($value) ? mb_substr($value, 0, $maxLength) : '';
    }

    /**
     * @inheritDoc
     */
    public function acceptsNotification(): bool
    {
        return false;
    }
}
