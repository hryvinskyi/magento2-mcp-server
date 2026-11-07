# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2] - 2026-11-07

### Changed
- Allows hryvinskyi/magento2-base 3.

## [1.4.1] - 2026-11-07

### Changed
- Handing out an integration's access token on the MCP Server page reads the integration and its token once each,
  instead of twice. The token handed out is the one the readiness check found.
- `IntegrationDirectoryInterface` lists integrations only; its `readinessOf()` is gone. The readiness of one
  integration comes from the new `IntegrationVerdictsInterface::verdictOf()`, whose `IntegrationVerdict` carries the
  token of a ready integration and never one of an integration that is not ready. Both interfaces are internal to the
  module (`Model/Admin/Integration`).
- The endpoint tests no longer run Directory or Customer abilities, which this module does not depend on: the
  anonymous, deny-list and confirmation cases call a test read that anyone may call, and the password-hash guard is
  proven on a test write it is given to guard. A separate integration test checks that the guard the container builds
  guards the customer save ability.
- The unit and browser tests no longer name Sales, Directory, Customer, Catalog or Inventory modules, which this module
  does not depend on: their fixtures use the modules it declares or the fictional `Acme_*` modules and `acme_*`
  abilities, and the integration tests that need a real ACL resource use a Backend one. The deny list and the
  password-hash guard tests keep the store modules' real names, which they are about.

## [1.4.0] - 2026-11-06

### Added
- **Install in one step** on the MCP Server page. Give the page an integration's access token and it fills it into an
  **Add to Cursor** link, an **Install in VS Code** link and a Claude Code command (`claude mcp add --transport http
  …`) with a copy button. A token pasted into the field stays in the browser tab and is never sent to the store. The
  Cursor and VS Code links appear only while the field holds a valid token; the command and the JSON configurations
  show the placeholder until then.
- **Pick an integration instead of pasting its token.** The page lists the store's integrations and says whether each
  can be used: it is active, its resources include **Use the MCP endpoint** (or all resources), and it has a token
  that is not revoked. Picking a ready one fills its access token into the field, fetched from the store by a POST
  with the form key to the new admin route `hryvinskyi_mcp/server/integrationtoken` (answered with `Cache-Control:
  no-store`, and only to an admin who holds **View the MCP Server page** and Magento's **System Integrations**
  resource, the right that shows tokens on the integration page). An integration that cannot be used says why and
  links to its edit page, and its token is never served. **+ New integration** opens Magento's own form in a new tab.
  An admin without **System Integrations** sees the manual field only; pasting a token by hand still works.
- A **warning** on the connection card while integration tokens are not accepted as Bearer tokens, which makes the
  store refuse every client that sends one, with a link to the OAuth settings for an admin who may open them.
- **Download for Claude Desktop (.mcpb)**: an extension for Claude Desktop, built for the store when you download it
  (it needs the `View the MCP Server page` resource, like the page). The download refuses an endpoint
  the extension could not use: one with no host, one that is neither https nor http, plain http to a host other
  than this computer, and one carrying a user name or password. Claude Desktop asks for the store endpoint,
  already filled in and editable, and for the access token, which it keeps in the system's keychain. The extension is
  named after the store's host, so several stores can be installed side by side. Its version (`1.0.0`) is separate from
  the package version.
- The extension runs a bridge written in Node.js, without dependencies, on the Node.js that ships with Claude Desktop.
  It relays each message to the endpoint with the Bearer token. It opens a lost session again once and replays the
  message, never retries a request, follows no redirect, and refuses an `http://` endpoint unless the host is
  `localhost`, `127.0.0.1` or `[::1]`, and a URL with a user name or password. Once the session is open it sends
  messages in parallel, so a long call blocks nothing. A refusal of a whole line (`id: null`) is answered once for
  each request of the line, a batch as one array.

### Fixed
- The stdio transport answers a refusal of a whole line (`id: null`, for example a lost session or an Internal error)
  once for each request on that line, with the request's own id; a batch gets one array. The client no longer waits
  for its timeout.

### Changed
- The MCP Server page is redesigned on the shared admin UI kit: cards for the connection and the install targets, the
  manual configurations and the technical details folded into collapsible blocks, snippets in a dark code block, the
  sections as a card whose switches change a section without reloading the page. The ability catalog stays a Magento
  listing with the admin theme's look; only the exposure and ACL cells its columns print are styled.
- Requires `hryvinskyi/magento2-admin-ui` (module `Hryvinskyi_AdminUi`), which carries the page's look and typeface.
  Run `bin/magento setup:upgrade` after the upgrade. On Magento 2.4.9 the admin action list is cached under the
  `compiled_config` cache type, so the new admin route answers 404 until `bin/magento cache:clean compiled_config` (or
  `cache:flush`) has run.
- The server name and the endpoint have one owner, `Model\Admin\ClientConnectionInterface`, which the page and the
  extension both use. The client configuration snippets have their own owner, `Model\Admin\ClientSnippetsInterface`.
- `Model\Http\UrlHost` is the one place that reads the host of a URL; the origin check and the extension use it.
- Requires `ext-zlib` (the extension is deflated in memory).
- The tests no longer drive Catalog abilities, which this module does not depend on: unit fixtures use Store
  vocabulary, and integration tests use Store routes, test Web API routes and a test pool ability.

## [1.3.0] - 2026-10-23

### Added
- Personal data is kept from clients: every value under a field the store lists as personal (email, names, phone,
  address lines, postcode, city, date of birth, tax and VAT numbers, gender, company, customer note, IP addresses, the
  card holder and the card's last digits, payment additional information, gift messages, shipping labels)
  reaches the client as `[personal data]`, however deep it sits. Custom attributes are judged by their code. Settings
  **Hide Personal Data** (on after install) and **Personal Data Fields** in the new group **Personal Data**.
- **Show personal data** and **Hide personal data** on the MCP Server page let chosen abilities send it; the catalog
  shows and filters whether each ability's personal data is hidden or shown, and the page warns while any ability sends
  it. `get_ability_info` answers `personalData`, and `initialize` tells the client that personal data arrives hidden.
- Sections: areas of the admin panel read from the ACL tree (Catalog, Sales, Customers, Stores › Inventory…), each
  holding the abilities whose ACL resources lie in it. The MCP Server page lists them with a switch; abilities of a
  section switched off are denied with the reason `section_off`. The catalog has a Section column and filter, and
  `get_ability_info` answers `sections`.
- `Api\ResultFilterInterface`: filters in the invoker's pool `resultFilters` change what a succeeded call returns
  before the listeners and the client see it. `Api\PersonalDataPolicyInterface`, `Api\PersonalDataExceptionsInterface`
  and `Api\SectionSwitchesInterface`.

### Changed
- The catalog page's changes are a pool of `Model\Admin\AbilityChangeInterface` in `di.xml` (`changes` of
  `Model\Admin\ExposureChanges`).

## [1.2.0] - 2026-10-23

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
