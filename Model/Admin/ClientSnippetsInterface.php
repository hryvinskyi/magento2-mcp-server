<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServer\Model\Admin;

use Hryvinskyi\McpServer\Console\Command\ServeCommand;

/**
 * The configuration an admin pastes into an MCP client, one per client and transport
 *
 * The placeholders below are the only stand-ins a configuration carries for what the admin fills in: the access token
 * and the admin login. No secret is ever part of a configuration; whatever writes the token into one in the browser
 * replaces the token placeholder.
 *
 * @phpstan-type Snippet array{id: string, client: string, transport: string, file: string, json: string, hint: string}
 */
interface ClientSnippetsInterface
{
    /**
     * Where the access token goes in a configuration
     */
    public const TOKEN_PLACEHOLDER = '<integration-token>';

    /**
     * The console command that serves MCP over standard input and output
     */
    public const SERVE_COMMAND = ServeCommand::NAME;

    /**
     * The command's option naming the admin user, with the login left for the admin to fill in
     */
    public const LOGIN_PLACEHOLDER = '--' . ServeCommand::OPTION_ADMIN_USER . '=<login>';

    /**
     * Every client configuration, in the order the page lists them
     *
     * @return list<Snippet>
     */
    public function all(): array;
}
