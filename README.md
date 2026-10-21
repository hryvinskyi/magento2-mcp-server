# MCP Server for Adobe Commerce (Magento 2)

Lets AI clients that speak the [Model Context Protocol](https://modelcontextprotocol.io) work with your store. The client finds and runs the operations of the store's Web API, as the admin user or integration it authenticates with.

## What it does

- Serves MCP over HTTP at `POST /rest/all/V1/mcp`, on Magento's REST stack. Magento authenticates the caller and checks the ACL.
- Turns every service method that a `webapi.xml` route calls into an *ability*, for example `catalog.product_repository.get_list`. A store has about 600 of them.
- Shows the client three tools only: `discover_abilities`, `get_ability_info` and `execute_ability`. The client searches the abilities and runs one by name.
- Blocks abilities that change data until you allow them (read-only mode), keeps abilities that any guest may call off until you allow them, asks for confirmation on abilities you mark, and always refuses a built-in list of dangerous ones.
- Keeps client sessions in the database.

## Requirements

- PHP 8.1 or higher
- Magento 2.4.x (Open Source or Adobe Commerce)

## Installation

### Via Composer (recommended)
```bash
composer require hryvinskyi/magento2-mcp-server
bin/magento module:enable Hryvinskyi_McpServer
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual Installation
1. Download the module from GitHub.
2. Place it in `app/code/Hryvinskyi/McpServer`.
3. Run:
```bash
bin/magento module:enable Hryvinskyi_McpServer
bin/magento setup:upgrade
bin/magento cache:flush
```

`setup:upgrade` creates three tables: `hryvinskyi_mcp_session`, `hryvinskyi_mcp_confirmation` and `hryvinskyi_mcp_ability_override`.

## Configuration

Go to **Stores > Configuration > MCP Server > MCP Server**, group **General**. All options are set in the default scope only.

| Option | Description | Default |
|--------|-------------|---------|
| Enable MCP Server | While it is off, the endpoint refuses every message | No |
| Read-only Mode | Clients may only call abilities that read data. Abilities that write or delete are blocked | Yes |
| Expose Anonymous Abilities | Lets clients call abilities that any guest may call through the REST API, such as account creation or guest checkout. Through MCP, the CAPTCHA and rate limits set for each REST route do not apply, so these abilities are denied while this is No | No |

The server is off after install. Turning it on is your step.

## Calling the endpoint

The URL is `https://<host>/rest/all/V1/mcp`.

- Use `POST` with a JSON-RPC 2.0 body. Send `Content-Type: application/json`.
- The store code must be `all`. `/rest/V1/mcp` and `/rest/default/V1/mcp` answer HTTP 400, because they would run abilities in a store view's scope.
- Send `Authorization: Bearer <token>` with every request.
- Serve the endpoint over HTTPS only. The token is a credential, and every answer can hold store data.
- A caller who is not authenticated, or whose role lacks **Use the MCP endpoint**, gets Magento's own 401 answer.
- While the server is disabled, the answer is HTTP 403 with JSON-RPC error `-32001`.

### Tokens

**Integration token (recommended).**
1. Go to **System > Extensions > Integrations** and add an integration.
2. On its **API** tab, give it the resource **Use the MCP endpoint**, plus whatever resources the abilities it will run need.
3. Activate the integration and copy its **Access Token**.
4. Magento accepts an integration's access token as a Bearer token only when **Stores > Configuration > Services > OAuth > Consumer Settings > Allow OAuth Access Tokens to be used as standalone Bearer tokens** is **Yes**. It is off by default (`oauth/consumer/enable_integration_as_bearer`).

**Admin token.** `POST /rest/V1/integration/admin/token` returns a token for an admin user. It expires after **Admin Token Lifetime (hours)** (`oauth/access_token_lifetime/admin`, default 4). With two-factor authentication enabled, that endpoint refuses to issue a token. Use an integration instead.

### Client configuration

**Cursor** (`.cursor/mcp.json`, a remote server with headers):

```json
{"mcpServers": {"magento": {"url": "https://<host>/rest/all/V1/mcp", "headers": {"Authorization": "Bearer <token>"}}}}
```

**Claude Desktop** (`claude_desktop_config.json`) starts local commands only. A remote server with a Bearer header goes through the `mcp-remote` bridge, which needs Node.js. Keep `Authorization:${AUTH_HEADER}` without a space, as `mcp-remote` documents:

```json
{"mcpServers": {"magento": {"command": "npx", "args": ["mcp-remote", "https://<host>/rest/all/V1/mcp", "--header", "Authorization:${AUTH_HEADER}"], "env": {"AUTH_HEADER": "Bearer <token>"}}}}
```

### Protocol

- The transport is Streamable HTTP with JSON responses. There is no event stream.
- The first message must be `initialize`. The answer carries the session id in the `Mcp-Session-Id` header. Send that header with every later message, notifications included.
- A missing header is HTTP 400. An unknown, expired or foreign session is HTTP 404. The client then sends `initialize` again.
- Supported protocol versions: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`. `initialize` answers with the client's version when it is supported, else with the newest. A later `MCP-Protocol-Version` header must name the version the session negotiated, or the request gets HTTP 400.
- Supported methods: `initialize`, `notifications/initialized`, `ping`, `tools/list` and `tools/call`. Any other method is JSON-RPC error `-32601`.
- Batches are accepted. `initialize` must be sent alone. In a batch, it gets the error `-32600` for its own element.
- A notification or a client response gets HTTP 202 with no body. Only `notifications/initialized` does anything as a notification. A request method sent without an `id`, `tools/call` included, runs nothing.
- `GET` answers HTTP 405 with `Allow: POST, DELETE`. `DELETE` with the session id ends that session (HTTP 204).
- A request with an `Origin` header is refused with HTTP 403 unless the header names a host of one of the store's base URLs.

### Sessions

- A session is stored in the `hryvinskyi_mcp_session` table. It belongs to the caller who opened it. Any other caller who sends its id gets 404.
- A session expires 24 hours after its last use.
- The cron job `hryvinskyi_mcp_session_cleanup` runs every hour and removes expired sessions.
- A caller holds at most 20 live sessions. When the caller opens one more, the sessions that were used least recently are ended. A client that gets HTTP 404 for its session opens a new one with `initialize`.
- Flushing caches does not end sessions.

### Limits

| Limit | Value | Answer when it is exceeded |
|-------|-------|----------------------------|
| Request body | 1 MB (1048576 bytes) | HTTP 400, error `-32600`, `id: null` |
| Messages in a batch | 20 | HTTP 400, error `-32600`, `id: null` |
| JSON nesting depth | 64 levels | HTTP 400, error `-32600`, `id: null` |
| Live sessions per caller | 20 | The least recently used sessions are ended |

A developer can change the body size, the batch size and the session count in `etc/di.xml`: the arguments `maxBodyBytes` of `Hryvinskyi\McpServer\Model\Protocol\Server`, `maxBatchSize` of `Hryvinskyi\McpServer\Model\Protocol\MessageParser` and `maxSessionsPerCaller` of `Hryvinskyi\McpServer\Model\Session\DbSessionStore`. The nesting depth is fixed.

## The three tools

Abilities are not listed as tools. A client finds one, reads its arguments, then runs it.

### `discover_abilities`

Searches the abilities. All arguments are optional.

| Argument | Description |
|----------|-------------|
| `query` | Words to find in the ability name, title or category. The search is case-insensitive. Empty lists every ability |
| `category` | Only abilities of this Magento module, for example `Magento_Catalog` |
| `access` | `read`, `write` or `delete` |
| `limit` | 1 to 100. Default 25 |
| `offset` | 0 or more. Default 0 |

The answer is `{total, abilities: [{name, title, category, access, exposure}]}`, sorted by name. Every ability is listed, whatever the caller's role allows.

### `get_ability_info`

Takes `name`. The answer is `{name, title, description, category, source, access, aclResources, exposure, allowedForYou, inputSchema}`.

`inputSchema` is a JSON Schema built from Magento's Web API metadata. Data types nest at most six levels in it. `allowedForYou` tells whether the caller's role holds every resource in `aclResources`.

### `execute_ability`

Runs an ability.

| Argument | Description |
|----------|-------------|
| `name` | The ability name. Required |
| `arguments` | An object keyed by argument name, as `inputSchema` describes. Default `{}` |
| `confirmationToken` | The token that the `-32004` error of this same call carried, sent once the user confirmed. Optional |

The old `confirm` argument is gone. A call that sends it gets a tool error that explains the token.

On success, the tool result holds the value as JSON text and as `structuredContent`. A scalar or a list is wrapped as `{"result": ...}`.

| Case | Answer |
|------|--------|
| Unknown ability, or arguments the service cannot take | A tool result with `isError: true` and a message, so the model can correct itself |
| The service fails | A tool result with `isError: true`. A message meant for users is passed on. Any other error is masked and logged |
| Denied | JSON-RPC error `-32003`, with `data: {ability, reason}`. See the reasons below |
| The ability needs a confirmation and the call has no valid token | JSON-RPC error `-32004`, with `data: {ability, confirmationToken}`. Ask the user, then call again with the same `name` and `arguments` plus that token |
| The ability must be called over HTTPS and the request was not | A tool result with `isError: true` |

The reasons of a `-32003` error:

| Reason | Why the call was denied |
|--------|-------------------------|
| `deny_list` | The ability is on the built-in deny-list or has the `deny` override |
| `read_only_mode` | Read-only mode is on and the ability writes or deletes |
| `anonymous_ability` | The ability is open to guests and **Expose Anonymous Abilities** is No |
| `acl` | The caller's role lacks an ACL resource of the ability |
| `argument_guard` | A guard refused the arguments of this call |

A module that adds an exposure rule can report a reason of its own (see Extending).

#### Confirmation tokens

An ability with the `confirm` override does not run on the first call. The error `-32004` carries a `confirmationToken`. The client asks the user, then calls `execute_ability` again with the same `name` and `arguments` and that token.

- A token works once and for 10 minutes.
- It works only for the session that got it, the same caller, the same ability and the same arguments. The order of the keys in `arguments` does not matter.
- The token is spent when it is sent, even when the call does not match it. The call then gets a new `-32004` error with a new token.
- An unknown, expired or spent token counts as no token.
- The cron job `hryvinskyi_mcp_session_cleanup` also removes expired tokens.

A token proves that the client made a second, explicit call for exactly these arguments. It does not prove that a person approved the call. A client that sends the token on its own, with no question to the user, gets the same result.

JSON-RPC errors that answer a parsed request come with HTTP 200 and the request's `id`. Input that cannot be parsed (`-32700`, or an invalid envelope `-32600`) is HTTP 400 with `id: null`.

## Abilities

An ability is one service method that a `webapi.xml` route calls.

- The name is `<module>.<service>.<method>`, in snake case. The module part of a Magento module is its name (`catalog`). Another vendor's module has its vendor in front (`acme_gallery`).
- When several routes call one method, the route with the fewest forced parameters is used.
- Routes that need the `self` resource (a customer acting for themselves) are not abilities.
- A route that `webapi.xml` marks as secure gives an ability that refuses a request that did not come over HTTPS. The refusal is a tool error.
- A route whose URL holds an id that the method does not take as a parameter, such as `PUT /V1/orders/:parent_id` on `save`, gives an ability with that id as an optional top-level argument. It selects the record to update, as in REST.
- The module's own endpoint is not an ability.
- The category is the Magento module name, for example `Magento_Catalog`.
- The access comes from the route's HTTP method: `GET` is `read`, `POST` and `PUT` are `write`, `DELETE` is `delete`. A developer can set the access of one ability by name in `etc/di.xml`, in the `accessOverrides` argument of `Hryvinskyi\McpServer\Model\Source\Webapi\RouteAbilityBuilder`. Use it for a `GET` route that changes data. The array ships empty. A value other than `read`, `write` or `delete` drops the ability and writes a warning to the log.
- An ability runs the same way a REST call does, with the caller's permissions. The caller needs every ACL resource of the route.
- If a route cannot become an ability (its name is invalid or taken), it is skipped and a warning goes to the log. The other abilities are not affected.

The input schemas are cached in the **MCP Server** cache type. Cleaning or flushing that cache type rebuilds them.

## Exposure

Every ability has one exposure. When several rules apply, the strictest wins, in this order:

| Order | Exposure | When |
|-------|----------|------|
| 1 | `denied` | The ability is on the built-in deny-list, has the `deny` override, or is open to guests while **Expose Anonymous Abilities** is No |
| 2 | `read_only_blocked` | Read-only mode is on and the ability is `write` or `delete` |
| 3 | `needs_confirmation` | The ability has the `confirm` override |
| 4 | `exposed` | None of the above |

A call that is denied changes nothing. The exposure, the caller's ACL, the confirmation and the argument guards are all checked before the ability runs.

### Read-only mode

It is on after install. Turn it off in the configuration to let clients run abilities that write or delete. Do this only with a token whose role holds just the resources you want a client to use.

### Built-in deny-list

These abilities are always denied, whatever the overrides say:

| Pattern | What it covers |
|---------|----------------|
| `integration.*` | Integrations, and the services that issue admin and customer tokens |
| `jwt_user_token.*` | JWT user tokens |
| `two_factor_auth.*` | Two-factor authentication, including its token endpoints |
| `login_as_customer*.*` | Login as customer |
| `customer.account_management.*password*` | Customer password reset and change |

The patterns are `fnmatch` patterns in `etc/di.xml`, in the `patterns` argument of `Hryvinskyi\McpServer\Model\Exposure\Rule\BuiltInDenyListRule`. Another module can add patterns there. The list cannot be changed in the configuration.

### Overrides

A per-ability override is a row in `hryvinskyi_mcp_ability_override`: `ability`, `deny` and `confirm`. Overrides only add restrictions. They cannot lift read-only mode or the built-in deny-list. The `Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface` reads and saves overrides. This version has no admin page to edit them (planned).

### Anonymous abilities

An ability whose only ACL resource is `anonymous` can be called by a guest through REST. Through MCP, the CAPTCHA and rate limits of each REST route do not apply. So these abilities are `denied` with the reason `anonymous_ability`, until you set **Expose Anonymous Abilities** to Yes. Turn it on only when you accept that.

### Argument guards

A guard looks at the arguments of one call and may refuse it. Guards run after the confirmation check and before the ability runs. A refusal is a `-32003` error with the reason `argument_guard`.

The module ships one guard. It refuses `customer.customer_repository.save` when the arguments hold a `passwordHash`. Passwords are never set through MCP that way.

### ACL

The caller's role is never used to hide an ability. `discover_abilities` lists all of them, and `get_ability_info` says what each needs. The ACL decides only when a call runs.

The module adds these ACL resources:

- **Stores > MCP Server** (`Hryvinskyi_McpServer::mcp_server`)
  - **Use the MCP endpoint** (`Hryvinskyi_McpServer::access`): required to call the endpoint
- **MCP Server Settings** (`Hryvinskyi_McpServer::config`): the configuration section

## Extending

- A source of abilities implements `Hryvinskyi\McpServer\Api\AbilitySourceInterface`. Add it to the `sources` argument of `Hryvinskyi\McpServer\Model\AbilityCatalog` in `di.xml`.
- An exposure rule implements `Hryvinskyi\McpServer\Api\ExposureRuleInterface`. Add it to the `rules` argument of `Hryvinskyi\McpServer\Model\Exposure\ExposurePolicy`.
- A guard implements `Hryvinskyi\McpServer\Api\InvocationGuardInterface`. Add it to the `guards` argument of `Hryvinskyi\McpServer\Model\AbilityInvoker`. `refusal()` returns the message of the refusal, or `null` to let the call through.
- An exposure rule that wants its own denial reason also implements `Hryvinskyi\McpServer\Api\ReasonedExposureRuleInterface`. Its `denialReason()` becomes the `reason` of the `-32003` error.
- To observe calls, implement `Hryvinskyi\McpServer\Api\InvocationListenerInterface`. Add it to the `listeners` argument of `Hryvinskyi\McpServer\Model\AbilityInvoker`. The invoker calls `started()` before the checks and `finished()` with the outcome after them, denials included. A listener that throws is logged and does not change the outcome of the call.

The module keeps no log of calls. Errors go to the PSR logger.

## Privacy and security

- What an ability returns goes to the client, and from there to the model provider the client uses. Customer data is part of that, as with REST. Give a token only the ACL resources that the client needs.
- Serve the endpoint over HTTPS only. A token or an answer sent over plain HTTP can be read on the way.
- A confirmation token is not an approval by a person. See *Confirmation tokens*.
- Abilities from routes that `webapi.xml` marks as secure refuse a request that did not come over HTTPS.

## Limits of this version

- The transport is HTTP only. The stdio transport is planned.
- Abilities come from the Web API (REST routes). GraphQL abilities are planned.
- There is no admin page to browse abilities or edit overrides yet. It is planned.
- The server answers only on the admin store, at `/rest/all/V1/mcp`.
- It offers tools only: no resources or prompts, and no event stream.

## Author

**Volodymyr Hryvinskyi**
- Email: volodymyr@hryvinskyi.com
- GitHub: https://github.com/hryvinskyi

## License

This module is licensed under the [MIT License](LICENSE).

## Support

For issues, feature requests, or questions, please contact the author or submit an issue on GitHub.
