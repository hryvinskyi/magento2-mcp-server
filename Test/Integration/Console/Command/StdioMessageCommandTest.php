<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Test\Integration\Console\Command;

use Hryvinskyi\McpServer\Console\Command\StdioMessageCommand;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\Config;
use Hryvinskyi\McpServer\Model\Session\SessionStoreInterface;
use Hryvinskyi\McpServer\Model\Source\GraphQl\GraphQlAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Pool\PoolAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiAbilitySource;
use Hryvinskyi\McpServer\Model\Source\Webapi\WebapiInvoker;
use Hryvinskyi\McpServer\Model\Stdio\CallerAdmission;
use Hryvinskyi\McpServer\Model\Stdio\CliAdminUserContext;
use Hryvinskyi\McpServer\Model\Stdio\Envelope;
use Hryvinskyi\McpServer\Model\Stdio\MessageHandler;
use Hryvinskyi\McpServer\Model\Transport\ChannelSecurity;
use Hryvinskyi\McpServer\Test\Integration\Fake\CapturedConsoleOutput;
use Hryvinskyi\McpServer\Test\Integration\Fixture\AdminRoles;
use Hryvinskyi\McpServer\Test\Integration\Fixture\BlocksRealGraphQlClient;
use Hryvinskyi\McpServer\Test\Integration\Fixture\McpAcl;
use Hryvinskyi\McpServer\Test\Integration\Fixture\SwitchesGraphQlSourceOff;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Authorization as FrameworkAuthorization;
use Magento\Framework\Authorization\RoleLocator\DefaultRoleLocator;
use Magento\Framework\Authorization\RoleLocatorInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Webapi\Authorization as WebapiAuthorization;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Bootstrap as TestBootstrap;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\User;
use Magento\Webapi\Model\WebapiRoleLocator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The hidden stdio message command, run the way the serving loop runs it: in the Web API area, as an admin found by
 * login, with the message on its standard input and the envelope on its standard output; a refused caller exits
 * with the command's own refusal code
 *
 * Each run starts from fresh shared instances of everything bound to a caller, as a new process would.
 */
#[AppArea('webapi_rest')]
#[DbIsolation(true)]
class StdioMessageCommandTest extends TestCase
{
    use BlocksRealGraphQlClient;
    use SwitchesGraphQlSourceOff;

    private const INITIALIZE = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18",'
        . '"capabilities":{},"clientInfo":{"name":"integration","version":"1"}}}';
    private const STORE_LIST = '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"execute_ability",'
        . '"arguments":{"name":"store.store_repository.get_list","arguments":{}}}}';

    /**
     * Shared instances that hold a user context, an ACL answer or the channel, dropped before and after each run
     */
    private const CALLER_BOUND = [
        CliAdminUserContext::class,
        WebapiRoleLocator::class,
        FrameworkAuthorization::class,
        WebapiAuthorization::class,
        McpAcl::AUTHORIZATION,
        McpAcl::WEBAPI_AUTHORIZATION,
        CallerAdmission::class,
        MessageHandler::class,
        ChannelSecurity::class,
        WebapiInvoker::class,
        WebapiAbilitySource::class,
        PoolAbilitySource::class,
        GraphQlAbilitySource::class,
        AbilityCatalog::class,
        AbilityInvoker::class,
    ];

    /**
     * The store that was current before the test
     *
     * @var string
     */
    private string $storeBefore = '';

    protected function setUp(): void
    {
        $this->storeBefore = $this->storeManager()->getStore()->getCode();
        $this->forgetCaller();
    }

    protected function tearDown(): void
    {
        $this->forgetCaller();
        $this->storeManager()->setCurrentStore($this->storeBefore);
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testASessionIsOpenedUsedForAnAdminReadAbilityAndEnded(): void
    {
        $startup = $this->runCommand(TestBootstrap::ADMIN_NAME, '');
        self::assertSame([0, '', ''], $startup);

        [$exit, $stdout, $stderr] = $this->runCommand(TestBootstrap::ADMIN_NAME, self::INITIALIZE);
        self::assertSame(0, $exit, $stderr);
        $initialized = $this->envelope($stdout);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $initialized['sessionId']);
        self::assertStringStartsWith('{"jsonrpc":"2.0","id":1,"result":{', $initialized['body']);
        $session = $initialized['sessionId'];
        self::assertInstanceOf(WebapiRoleLocator::class, $this->objectManager()->get(RoleLocatorInterface::class));

        [, $stdout] = $this->runCommand(
            TestBootstrap::ADMIN_NAME,
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            ['--session' => $session]
        );
        $tools = $this->decode($this->envelope($stdout)['body']);
        self::assertSame(
            ['discover_abilities', 'get_ability_info', 'execute_ability'],
            array_column($this->arrayAt($this->arrayAt($tools, 'result'), 'tools'), 'name')
        );

        [, $stdout] = $this->runCommand(TestBootstrap::ADMIN_NAME, self::STORE_LIST, ['--session' => $session]);
        $this->assertStoresListed($this->envelope($stdout));

        $ended = $this->runCommand(TestBootstrap::ADMIN_NAME, '', ['--session' => $session, '--end-session' => true]);
        self::assertSame([0, '', ''], $ended);
        self::assertNull($this->sessions()->find($session));
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnAclBuiltForTheGlobalAreaBeforehandDecidesNothing(): void
    {
        $noAccess = $this->createAdmin('mcp_stdio_no_access', true);
        AdminRoles::grant(['Magento_Backend::store'], $noAccess);

        [$exit, $stdout, $stderr] = $this->runCommand(TestBootstrap::ADMIN_NAME, self::INITIALIZE, [], true);
        self::assertSame(0, $exit, $stderr);
        $session = $this->envelope($stdout)['sessionId'];
        [, $stdout] = $this->runCommand(TestBootstrap::ADMIN_NAME, self::STORE_LIST, ['--session' => $session], true);
        $this->assertStoresListed($this->envelope($stdout));

        [$refusedExit, $refusedStdout, $refusedStderr] = $this->runCommand('mcp_stdio_no_access', '', [], true);
        self::assertSame(StdioMessageCommand::EXIT_REFUSED, $refusedExit);
        self::assertSame('', $refusedStdout);
        self::assertStringContainsString('does not allow "Use the MCP endpoint"', $refusedStderr);

        $planted = $this->objectManager()->get(AuthorizationInterface::class);
        self::assertInstanceOf(AuthorizationInterface::class, $planted);
        self::assertFalse(
            $planted->isAllowed('Hryvinskyi_McpServer::access'),
            'The planted ACL must be one that denies the admin, or the test proves nothing.'
        );
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnUnknownAdminIsRefused(): void
    {
        $this->assertRefused('mcp_stdio_nobody', 'There is no admin user with the login "mcp_stdio_nobody".');
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnInactiveAdminIsRefused(): void
    {
        $this->createAdmin('mcp_stdio_inactive', false, TestBootstrap::ADMIN_ROLE_ID);

        $this->assertRefused('mcp_stdio_inactive', 'The admin user "mcp_stdio_inactive" is inactive.');
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testALockedAdminIsRefused(): void
    {
        $id = $this->createAdmin('mcp_stdio_locked', true, TestBootstrap::ADMIN_ROLE_ID);
        $users = $this->objectManager()->get(UserResource::class);
        self::assertInstanceOf(UserResource::class, $users);
        $users->lock([$id], 0, 3600);

        $this->assertRefused('mcp_stdio_locked', 'The admin user "mcp_stdio_locked" is locked.');
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnAdminPastItsExpiryDateIsRefusedThoughStillActive(): void
    {
        $id = $this->createAdmin('mcp_stdio_expired', true, TestBootstrap::ADMIN_ROLE_ID);
        $resource = $this->objectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->insert(
            $resource->getTableName('admin_user_expiration'),
            ['user_id' => $id, 'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)]
        );

        $this->assertRefused('mcp_stdio_expired', 'The admin user "mcp_stdio_expired" has expired.');
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAnAdminWhoseExpiryDateIsAheadIsServed(): void
    {
        $id = $this->createAdmin('mcp_stdio_expires_later', true, TestBootstrap::ADMIN_ROLE_ID);
        $resource = $this->objectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->insert(
            $resource->getTableName('admin_user_expiration'),
            ['user_id' => $id, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]
        );

        self::assertSame([0, '', ''], $this->runCommand('mcp_stdio_expires_later', ''));
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testAMessageInASessionTheServerNoLongerKnowsReportsItLost(): void
    {
        [$exit, $stdout, $stderr] = $this->runCommand(
            TestBootstrap::ADMIN_NAME,
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            ['--session' => str_repeat('ab', 16)]
        );

        self::assertSame(0, $exit, $stderr);
        $envelope = $this->envelope($stdout);
        self::assertTrue($envelope['sessionLost']);
        self::assertSame('', $envelope['sessionId']);
        self::assertArrayHasKey('error', $this->decode($envelope['body']));

        [, $stdout] = $this->runCommand(TestBootstrap::ADMIN_NAME, '{"jsonrpc":"2.0","id":3,"method":"ping"}');
        self::assertFalse($this->envelope($stdout)['sessionLost'], 'A message that named no session lost none.');
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '1')]
    public function testARoleWithoutAccessIsRefused(): void
    {
        $id = $this->createAdmin('mcp_stdio_store_only', true);
        AdminRoles::grant(['Magento_Backend::store'], $id);

        $this->assertRefused(
            'mcp_stdio_store_only',
            'The role of the admin user "mcp_stdio_store_only" does not allow "Use the MCP endpoint" '
            . '(Hryvinskyi_McpServer::access).'
        );
    }

    #[ConfigFixture(Config::XML_PATH_ENABLED, '0')]
    public function testADisabledServerIsRefused(): void
    {
        $this->assertRefused(TestBootstrap::ADMIN_NAME, 'The MCP server is disabled.');
    }

    public function testAMissingLoginIsRefused(): void
    {
        $command = $this->objectManager()->create(StdioMessageCommand::class);
        self::assertInstanceOf(StdioMessageCommand::class, $command);
        $output = new CapturedConsoleOutput();

        self::assertSame(StdioMessageCommand::EXIT_REFUSED, $command->run(new ArrayInput([]), $output));
        self::assertSame('', $output->written());
        self::assertSame("The --admin-user option is required.\n", $output->writtenToErrorOutput());
    }

    /**
     * Run the command with a message, or with none for the startup check, as a new process would
     *
     * @param string $login
     * @param string $message
     * @param array<string, string|bool> $options
     * @param bool $withGlobalAcl Whether the shared ACL is one built for the global area, as other commands leave it
     * @return array{int, string, string} The exit code, standard output and standard error
     */
    private function runCommand(
        string $login,
        string $message,
        array $options = [],
        bool $withGlobalAcl = false
    ): array {
        $this->forgetCaller();
        if ($withGlobalAcl) {
            $this->plantGlobalAcl();
        }
        $command = $this->objectManager()->create(StdioMessageCommand::class);
        self::assertInstanceOf(StdioMessageCommand::class, $command);
        $input = new ArrayInput(['--admin-user' => $login] + $options);
        $input->setInteractive(false);
        $stdin = fopen('php://memory', 'w+');
        self::assertIsResource($stdin);
        fwrite($stdin, $message);
        rewind($stdin);
        $input->setStream($stdin);
        $output = new CapturedConsoleOutput();

        $exit = $command->run($input, $output);

        return [$exit, $output->written(), $output->writtenToErrorOutput()];
    }

    /**
     * @param string $login
     * @param string $message
     * @return void
     */
    private function assertRefused(string $login, string $message): void
    {
        self::assertSame(
            [StdioMessageCommand::EXIT_REFUSED, '', $message . "\n"],
            $this->runCommand($login, self::INITIALIZE)
        );
    }

    /**
     * @param array{body: string, sessionId: string, sessionLost: bool} $envelope
     * @return void
     */
    private function assertStoresListed(array $envelope): void
    {
        $result = $this->arrayAt($this->decode($envelope['body']), 'result');
        self::assertFalse($result['isError'] ?? null, $envelope['body']);
        $stores = $this->arrayAt($result, 'structuredContent')['result'] ?? null;
        self::assertIsArray($stores);
        self::assertContains('admin', array_column($stores, 'code'));
        self::assertContains('default', array_column($stores, 'code'));
    }

    /**
     * Make the shared ACL one built for the global area: its role locator finds no role for anyone
     *
     * @return void
     */
    private function plantGlobalAcl(): void
    {
        $objectManager = $this->objectManager();
        $stale = $objectManager->create(
            FrameworkAuthorization::class,
            ['roleLocator' => $objectManager->create(DefaultRoleLocator::class)]
        );
        $objectManager->addSharedInstance($stale, FrameworkAuthorization::class);
    }

    /**
     * Store an admin user and return its id
     *
     * @param string $login
     * @param bool $active
     * @param int|null $roleId The role to assign, or none
     * @return int
     */
    private function createAdmin(string $login, bool $active, ?int $roleId = null): int
    {
        $user = $this->objectManager()->create(User::class);
        self::assertInstanceOf(User::class, $user);
        $user->setFirstname('Mcp')
            ->setLastname('Stdio')
            ->setUsername($login)
            ->setPassword(TestBootstrap::ADMIN_PASSWORD)
            ->setEmail($login . '@example.com')
            ->setIsActive($active ? 1 : 0);
        if ($roleId !== null) {
            $user->setData('role_id', $roleId);
        }
        $user->save();
        $id = $user->getId();
        self::assertIsNumeric($id);

        return (int) $id;
    }

    /**
     * Drop every shared instance bound to a caller, so the next run builds them as a new process would
     *
     * @return void
     */
    private function forgetCaller(): void
    {
        $objectManager = $this->objectManager();
        $objectManager->removeSharedInstance(UserContextInterface::class, true);
        foreach (self::CALLER_BOUND as $class) {
            $objectManager->removeSharedInstance($class);
        }
        AdminRoles::forgetAcl();
    }

    /**
     * @param string $stdout
     * @return array{body: string, sessionId: string, sessionLost: bool}
     */
    private function envelope(string $stdout): array
    {
        self::assertSame(1, substr_count($stdout, "\n"), $stdout);
        $envelope = $this->objectManager()->get(Envelope::class);
        self::assertInstanceOf(Envelope::class, $envelope);
        $decoded = $envelope->decode($stdout);
        self::assertNotNull($decoded, $stdout);

        return $decoded;
    }

    /**
     * @param string $json
     * @return array<mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $array
     * @param string|int $key
     * @return array<mixed>
     */
    private function arrayAt(array $array, string|int $key): array
    {
        $value = $array[$key] ?? null;
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return SessionStoreInterface
     */
    private function sessions(): SessionStoreInterface
    {
        $sessions = $this->objectManager()->get(SessionStoreInterface::class);
        self::assertInstanceOf(SessionStoreInterface::class, $sessions);

        return $sessions;
    }

    /**
     * @return StoreManagerInterface
     */
    private function storeManager(): StoreManagerInterface
    {
        $storeManager = $this->objectManager()->get(StoreManagerInterface::class);
        self::assertInstanceOf(StoreManagerInterface::class, $storeManager);

        return $storeManager;
    }

    /**
     * @return ObjectManager
     */
    private function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
