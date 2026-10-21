# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
