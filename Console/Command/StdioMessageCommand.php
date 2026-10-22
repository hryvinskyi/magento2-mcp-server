<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Console\Command;

use Hryvinskyi\McpServer\Model\Stdio\MessageHandler;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaInterface;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * `bin/magento hryvinskyi:mcp:stdio-message`: one message of the stdio transport, in a process of its own; the
 * serving command starts it for every line, and people never call it directly, so it is hidden
 *
 * It loads Magento's Web API area (REST) first: anonymous ACL resources, the Web API role locator and the user
 * contexts the CLI admin joins are configured there only. The area is set through the application state and its
 * configuration loaded through the area list, the way Magento's own application entry points do. An area already set
 * to the Web API one is kept; any other area already set is refused, since its configuration is the wrong one.
 *
 * The constructor takes nothing but the state, the area list and the message handler, which arrives as a lazy proxy:
 * Magento builds every command for every `bin/magento` run, before any area is loaded, and an object built then keeps
 * the global configuration for the rest of the process. The handler and everything it needs are built on first use,
 * after the area is loaded.
 *
 * Exit codes:
 * - 0 after an answer, or after an empty startup check;
 * - 77 (EXIT_REFUSED) when the caller or the area is refused, with the reason on standard error and nothing on
 *   standard output. The serving process stops on this code alone;
 * - any other code (Symfony's console exits 1 on an exception nothing caught) is a failure of this one message.
 */
class StdioMessageCommand extends Command
{
    public const NAME = 'hryvinskyi:mcp:stdio-message';
    public const OPTION_ADMIN_USER = 'admin-user';
    public const OPTION_SESSION = 'session';
    public const OPTION_END_SESSION = 'end-session';

    /**
     * The exit code of a refused caller, kept apart from the 1 that any uncaught failure gives
     */
    public const EXIT_REFUSED = 77;

    /**
     * @param State $state
     * @param AreaList $areaList
     * @param MessageHandler $handler
     */
    public function __construct(
        private readonly State $state,
        private readonly AreaList $areaList,
        private readonly MessageHandler $handler
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Handle one MCP message from standard input for the stdio server (internal)')
            ->setHidden(true)
            ->addOption(self::OPTION_ADMIN_USER, null, InputOption::VALUE_REQUIRED, 'Login of the admin user')
            ->addOption(self::OPTION_SESSION, null, InputOption::VALUE_REQUIRED, 'The MCP session id, when known')
            ->addOption(self::OPTION_END_SESSION, null, InputOption::VALUE_NONE, 'End the session instead');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $login = $input->getOption(self::OPTION_ADMIN_USER);
        if (!is_string($login) || $login === '') {
            $errorOutput->writeln(
                sprintf('The --%s option is required.', self::OPTION_ADMIN_USER),
                OutputInterface::OUTPUT_RAW
            );

            return self::EXIT_REFUSED;
        }

        $currentArea = $this->currentArea();
        if ($currentArea !== null && $currentArea !== Area::AREA_WEBAPI_REST) {
            $errorOutput->writeln(
                sprintf(
                    'The %s area is already set in this process; MCP messages need the webapi_rest area.',
                    $currentArea
                ),
                OutputInterface::OUTPUT_RAW
            );

            return self::EXIT_REFUSED;
        }

        if ($currentArea === null) {
            $this->state->setAreaCode(Area::AREA_WEBAPI_REST);
        }
        $area = $this->areaList->getArea(Area::AREA_WEBAPI_REST);
        $area->load(AreaInterface::PART_CONFIG);
        $area->load(AreaInterface::PART_TRANSLATE);

        $session = $input->getOption(self::OPTION_SESSION);

        return $this->handler->handle(
            $login,
            is_string($session) ? $session : '',
            $input->getOption(self::OPTION_END_SESSION) === true,
            $input instanceof StreamableInputInterface ? $input->getStream() : null,
            $output instanceof StreamOutput ? $output->getStream() : null,
            $errorOutput instanceof StreamOutput ? $errorOutput->getStream() : null
        );
    }

    /**
     * The area already set in this process, or null when none is
     *
     * @return string|null
     */
    private function currentArea(): ?string
    {
        try {
            $code = $this->state->getAreaCode();
        } catch (LocalizedException) {
            return null;
        }

        return $code === '' ? null : $code;
    }
}
