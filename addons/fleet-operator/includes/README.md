# fleet-operator/includes

## Purpose

This folder houses the six PHP classes that implement the Fleet Operator
addon's scoped `op_` credential lifecycle, server-side tool allowlisting, and
Hermes config generation — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Addon (`addons/fleet-operator/`, GPL-3.0) — requires the base plugin |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/fleet-operator/fleet-operator.php` (explicit `require_once` list) |
| **Optional dependencies** | none (integrates with the base plugin via public hooks) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Operator_Credential_Repository` | `class-wp-mcp-ai-operator-credential-repository.php` | admin, CLI, authenticator, tool scope |
| `WP_MCP_AI_Operator_Authenticator` | `class-wp-mcp-ai-operator-authenticator.php` | base plugin REST auth hooks (`wp_mcp_ai_pre_validate_bearer_token`, `wp_mcp_ai_map_bearer_to_user_id`) |
| `WP_MCP_AI_Operator_Tool_Scope` | `class-wp-mcp-ai-operator-tool-scope.php` | base plugin MCP `tools/list` + `tools/call` hooks |
| `WP_MCP_AI_Operator_Config_Generator` | `class-wp-mcp-ai-operator-config-generator.php` | admin (Hermes config download), CLI |
| `WP_MCP_AI_Operator_Admin` | `class-wp-mcp-ai-operator-admin.php` | admin menu page |
| `WP_MCP_AI_Operator_CLI` | `class-wp-mcp-ai-operator-cli.php` | WP-CLI commands |

Anything not listed here is internal and may change without notice.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_options` operator credential records (hashed tokens,
  audience binding, expiry, rate limits), base plugin settings registry.
- **Writes to:** operator credential options; audit log entries attributed
  to the authorizing user.
- **Upstream callers:** the addon entry file, WP admin, WP-CLI, and the base
  plugin's REST/MCP pipeline via hooks.
- **Downstream collaborators:** `includes/class-wp-mcp-ai-rest-mcp-methods.php`
  and `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` (scoping hooks).
- **Events fired:** none public.
- **Events listened to:** `wp_mcp_ai_pre_validate_bearer_token`,
  `wp_mcp_ai_map_bearer_to_user_id`, MCP tools/list and tools/call filters
  (all prefixed, defined by the base plugin).

## Conventions

- Tokens follow the `op_xxxxx.SECRET` format; only hashes are stored — the
  secret is shown once at creation (admin) and embedded in the generated
  Hermes config.
- All base-plugin integration goes through public hooks — no private API
  calls into the base plugin.

## Tests

```bash
vendor/bin/phpunit tests/fleet-operator/
```

406 lines of PHPUnit coverage (credentials, scoping, revocation, config
generation).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (token handling is in scope)
- [`.context/rest-api.md`](../../.context/rest-api.md) — MCP/REST hook semantics
- [`addons/fleet-operator/README.md`](../README.md) — addon overview and setup

## See Also

- Upstream parent: [`addons/fleet-operator/`](../)
- Runbook: [`docs/operations/fleet/hermes-operator-setup.md`](../../docs/operations/fleet/hermes-operator-setup.md)
- Implementation plan: [`docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md`](../../docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md)
- Related: [`bin/hermes-mcp-server.js`](../../bin/hermes-mcp-server.js) (Zed → Hermes MCP bridge; the operator credentials gate the tools it can call)
