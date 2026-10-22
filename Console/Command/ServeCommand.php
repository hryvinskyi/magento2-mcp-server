<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Console\Command;

use Hryvinskyi\McpServer\Model\Stdio\StdioLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * `bin/magento hryvinskyi:mcp:serve --admin-user=<login>`: the MCP server over standard input and output, for a
 * client on this machine, as that admin
 *
 * It only reads its option and the streams and hands them to the stdio loop, which it receives as a lazy proxy so
 * that other commands never build it. Answers go to the output stream as raw lines, never through the console
 * formatter; everything else goes to standard error.
 */
class ServeCommand extends Command
{
    public const NAME = 'hryvinskyi:mcp:serve';
    public const OPTION_ADMIN_USER = 'admin-user';

    private const EXIT_REFUSED = 1;

    /**
     * @param StdioLoop $loop
     */
    public function __construct(
        private readonly StdioLoop $loop
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Serve MCP over standard input and output, as an admin user, for a local MCP client')
            ->addOption(
                self::OPTION_ADMIN_USER,
                null,
                InputOption::VALUE_REQUIRED,
                'Login of the admin user the MCP client acts as'
            );
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

        return $this->loop->run(
            $login,
            $input instanceof StreamableInputInterface ? $input->getStream() : null,
            $output instanceof StreamOutput ? $output->getStream() : null,
            $errorOutput instanceof StreamOutput ? $errorOutput->getStream() : null
        );
    }
}
