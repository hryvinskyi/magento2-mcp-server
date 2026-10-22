# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Stores › Settings › MCP Server**: the server's state, the endpoint URL and the stdio command, how to authorize, the
  protocol version and how many abilities a client may call, and a configuration to copy per client (Claude Desktop,
  Cursor) and transport (HTTP, stdio). No secret is printed: the token and the login are placeholders.
- The ability catalog on that page: every ability with its source, module, access, required ACL and exposure, with the
  reason. Search and filters by source, module, access and exposure.
- Changing exposure from the page: a row puts its ability on the deny-list or takes it off; mass actions add to or
  remove from the deny-list, and require or clear confirmation, for the selection or for every ability the search
  finds. They need the new resource **Manage ability exposure**; the page needs **View the MCP Server page**.
- `get_ability_info` answers `denialReason` for an ability no client may call.
- The admin menu block of `hryvinskyi/magento2-base` on the page and the settings section (handle
  `hryvinskyi_mcp_server_menu`, which another module adds items to).

### Changed
- Requires `hryvinskyi/magento2-base` ^2.2 and `magento/module-ui`.

### Fixed
- An input schema whose properties (or other named schemas) are named `"0"`, `"1"`, … in order is listed as a JSON
  object; it was listed as an array.

## [1.1.1] - 2026-10-22

### Fixed
- In production mode the stdio admin context took every process for a command line, so its guard against
  web requests did not hold (the context still answers only after the stdio command sets the admin). The
  server API is now read when asked instead of being a constructor default, which `setup:di:compile` keeps
  as the compiling command line saw it.
- The stdio server runs message processes with the PHP interpreter it runs under, read at run time, instead
  of the one that compiled the configuration.

## [1.1.0] - 2026-10-22

### Added
- The stdio transport: `bin/magento hryvinskyi:mcp:serve --admin-user=<login>` serves MCP over standard
  input and output for a local client, as that admin user. It reads one JSON-RPC message or batch per line.
  It exits 0 at the end of the input. It exits 1, with a message on standard error, when the option is
  missing, when the admin is unknown, inactive, locked or past its expiry date, when the role lacks **Use the
  MCP endpoint**, when the server is disabled, when another area is already set, and when the admin loses
  access while it runs. A line over 1 MB is answered with `-32600`.
- Each stdio message runs in a new `bin/magento` process (the hidden, internal command
  `hryvinskyi:mcp:stdio-message`), so settings, admin accounts and roles are read again for every message.
  The cost is about 0.4 s per message on a development machine. One process also runs before the first
  line is read, and any failure there stops `serve` with exit 1. A message process may run for 300 seconds
  (`messageTimeoutSeconds` of `Model\Stdio\StdioLoop`).
- A stdio session counts toward the admin's 20 live sessions, shared with HTTP. If a client sends
  `initialize` again, stdio switches to the new session. A session that expired, or that the cap ended, is
  opened again once with the client's own `initialize`, and the message is sent again.
- A stdio message whose process fails is answered with `-32603` for each of its requests, with their ids;
  a notification gets no answer.
- Web API routes that `webapi.xml` marks as secure run over stdio, because a local pipe counts as a secure
  channel (`Model\Transport\ChannelSecurityInterface`).
- GraphQL abilities: each query field of the store's GraphQL schema is an ability named
  `graphql.query.<field>`, in the category `GraphQL`, with `read` access. They run as a guest, through the
  store's own `/graphql`, in the store view that **Store View** names. The schema is read by introspection and
  cached in the **MCP Server** cache type.
- GraphQL mutation fields as abilities named `graphql.mutation.<field>`, with `write` access, only while
  **Expose GraphQL Mutations** is Yes.
- The `selection` argument of a GraphQL ability, the selection set of the result. It is exactly one group in
  braces, with no `#` comments.
- The ability `graphql.schema.describe_type`, which describes one type of the GraphQL schema.
- The settings group **GraphQL** with **Enable GraphQL Abilities** (default Yes), **Expose GraphQL Mutations**
  (default No), **GraphQL Endpoint URL** (an absolute http(s) URL ending in `/graphql`; empty means the store
  view's secure base URL followed by `graphql`) and **Store View** (empty means the default store view).
- Every GraphQL ability is denied until **Expose Anonymous Abilities** is Yes. The query fields `customer`,
  `customerCart`, `customerDownloadableProducts`, `customerOrders`, `customerPaymentTokens` and `wishlist` are
  never listed, because a guest always gets a 401 from them (`excludedFields` of
  `Model\Source\GraphQl\GraphQlAbilitySource`).
- Two patterns on the built-in deny-list: `graphql.mutation.*token*` and `graphql.mutation.*password*`.
- Registered abilities: a module implements `Api/PoolAbilityInterface` and adds the class to the `abilities`
  argument of `Model\Source\Pool\PoolAbilitySource` in `di.xml`, keyed by the ability's name. An item with
  no ACL resource, or with a name or title that breaks the rules, is skipped with a warning.
- A data patch that enables the **MCP Server** cache type once. `setup:upgrade` leaves a newly added cache
  type disabled.
- Requirements: `magento/module-user`, `magento/module-security`, `symfony/console` and `symfony/process`.

### Changed
- Ability sources are sorted by their `sortOrder` in the `sources` argument of `Model\AbilityCatalog`: `pool` is
  10, `webapi` is 20 and `graphql` is 30. A source that has no `sortOrder` sorts first, and when two sources
  offer one name, the first keeps it. A registered ability wins over a Web API ability of the same name.
- The `category` argument of `discover_abilities` also takes `GraphQL`.
- **Expose Anonymous Abilities** also lets GraphQL abilities run, since they all run as a guest. Set **Enable
  GraphQL Abilities** to No to keep 1.0.0's exposure.

### Not in this version
- An admin page to browse abilities and edit overrides is planned.

## [1.0.0] - 2026-10-21

### Added
- An MCP server over Streamable HTTP with JSON responses, at `POST /rest/all/V1/mcp`.
  `GET` answers 405. `DELETE` ends a session.
- Authentication and the endpoint's ACL resource (**Use the MCP endpoint**) come from Magento's
  own Web API checks. The endpoint answers only on the admin store, which is the store code `all`.
- Protocol versions `2025-11-25`, `2025-06-18`, `2025-03-26` and `2024-11-05`. Batches are accepted.
  A request with an `Origin` header that names a host the store does not serve is refused.
- Sessions stored in the `hryvinskyi_mcp_session` table. A session expires 24 hours after its last use.
  The cron job `hryvinskyi_mcp_session_cleanup` removes expired sessions every hour.
- Three meta-tools: `discover_abilities`, `get_ability_info` and `execute_ability`. Abilities are
  searched and run through them, never listed as tools.
- Abilities from the Web API: one ability for each service method that a `webapi.xml` route calls,
  named `<module>.<service>.<method>`. Its input schema is built from Magento's Web API metadata and
  cached in the `MCP Server` cache type.
- An exposure policy: a deny-list, read-only mode, per-ability confirmation, and the caller's ACL.
  Read-only mode is on after install. The server is off after install.
- A built-in deny-list for token issuing, integrations, JWT user tokens, two-factor authentication,
  login as customer and customer password abilities.
- Per-ability overrides (`deny`, `confirm`) in the `hryvinskyi_mcp_ability_override` table, with a
  repository API.
- Extension points: ability sources, exposure rules and invocation listeners are `di.xml` pools.
- Configuration at **Stores > Configuration > MCP Server > MCP Server**: **Enable MCP Server**,
  **Read-only Mode** and **Expose Anonymous Abilities**.
- Confirmation tokens. When an ability needs a confirmation, the `-32004` error carries a
  `confirmationToken`, and `execute_ability` takes it in the argument `confirmationToken`. A token works
  once, for 10 minutes, and only for the same session, caller, ability and arguments. It is spent even
  when the call does not match it. It proves that the client made a second, explicit call, not that a
  person approved it. Tokens are stored in the `hryvinskyi_mcp_confirmation` table, and the hourly cron
  job removes expired ones. The older `confirm` argument is not taken: a call that sends it gets a tool
  error.
- Limits: a request body of at most 1 MB, a batch of at most 20 messages, JSON nested at most 64
  levels, and at most 20 live sessions for each caller (the least recently used are ended). The
  body size, batch size and session count are `di.xml` arguments.
- A message sent without an `id` runs nothing, except `notifications/initialized`. A batch that holds
  `initialize` gets the error `-32600` for that element. A failure while checking the session keeps the
  request's `id` in its `-32603` answer.
- Argument guards: the extension point `Api/InvocationGuardInterface` and the `guards` pool of
  `Model\AbilityInvoker`. A refusal is a `-32003` error with the reason `argument_guard`. The built-in
  guard refuses `customer.customer_repository.save` with a `passwordHash`: passwords are never set
  through MCP.
- Abilities that any guest may call are off by default. The setting **Expose Anonymous Abilities**
  turns them on. While it is off, they are denied with the reason `anonymous_ability`, because the CAPTCHA
  and rate limits of each REST route do not apply through MCP.
- Denial reasons: `deny_list`, `read_only_mode`, `anonymous_ability`, `acl` and `argument_guard`.
  Another module can report its own reason through `Api/ReasonedExposureRuleInterface`.
- An ability from a route that `webapi.xml` marks as secure refuses a request that did not come over
  HTTPS.
- Access overrides: the `accessOverrides` argument of `Model\Source\Webapi\RouteAbilityBuilder` sets the
  access (`read`, `write` or `delete`) of one ability by name. It ships empty.
- Input schemas list as required only the service method's own parameters, never the fields of a data
  object. An update route with an id in its URL, such as `PUT /V1/orders/:parent_id`, gives an optional
  top-level argument for that id.
- A README section on privacy and security: ability output, customer data included, goes to the client
  and its model provider, as with REST. Serve the endpoint over HTTPS only.
- ACL resources **MCP Server** and **Use the MCP endpoint**, and **MCP Server Settings** for the
  configuration section.

### Not in this version
- The stdio transport is planned.
- GraphQL abilities are planned.
- An admin page to browse abilities and edit overrides is planned.
