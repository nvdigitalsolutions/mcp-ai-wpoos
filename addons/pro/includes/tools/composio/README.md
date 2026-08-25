# Composio Tools

## Purpose

The seven `composio_*` MCP tools that expose Composio Connect to NV oOS assistants: catalog discovery, schema lookup, connected-account listing with verified health, connected-account lifecycle management, Connect Link creation, tool execution and trigger management.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/composio/composio-init.php` (priority 45 on `wp_mcp_ai_bootstrapped`) |
| **Optional dependencies** | none — every tool degrades to a clear `WP_Error` when no enabled `composio` connection exists |

## Public Surface

| Class | Slug | Capability | Risk |
|---|---|---|---|
| `WP_MCP_AI_Tool_Composio_List_Tools` | `composio_list_tools` | `edit_posts` | low |
| `WP_MCP_AI_Tool_Composio_Get_Tool_Schema` | `composio_get_tool_schema` | `edit_posts` | low |
| `WP_MCP_AI_Tool_Composio_List_Connected_Accounts` | `composio_list_connected_accounts` | `manage_options` | medium |
| `WP_MCP_AI_Tool_Composio_Manage_Accounts` | `composio_manage_accounts` | `manage_options` | high |
| `WP_MCP_AI_Tool_Composio_Create_Connect_Link` | `composio_create_connect_link` | `manage_options` | medium |
| `WP_MCP_AI_Tool_Composio_Execute_Tool` | `composio_execute_tool` | `manage_options` | high |
| `WP_MCP_AI_Tool_Composio_Manage_Triggers` | `composio_manage_triggers` | `manage_options` | medium |
| `WP_MCP_AI_Composio_Tools` | (static helper — not a tool) | — | — |

## Inputs / Outputs / Neighbors

- **Reads from:** Remote Site Manager `composio` connections; Composio API v3.1 via `WP_MCP_AI_Composio_Client`; stored credential verdicts via `WP_MCP_AI_Composio_Account_Health`.
- **Writes to:** Composio connected accounts, tool executions and trigger instances (write-class tools only); the per-connection health ledger on every execution outcome and probe.
- **Upstream callers:** tool registry, chat orchestrator.
- **Downstream collaborators:** [`WP_MCP_AI_Composio_Client`](../../composio/class-wp-mcp-ai-composio-client.php), [`WP_MCP_AI_Composio_Account_Health`](../../composio/class-wp-mcp-ai-composio-account-health.php), [`WP_MCP_AI_Composio_Auth_Handler`](../../composio/class-wp-mcp-ai-composio-auth-handler.php).
- **Events fired:** `wp_mcp_ai_composio_tool_executed`, `wp_mcp_ai_composio_account_managed`.
- **Events listened to:** none.

## Conventions

- Canonical envelope (`format_success_response()` via `WP_MCP_AI_Tool_Envelope`) — success arrays only, failures are `WP_Error`.
- Two-gate sanitisation: sanitize `$arguments[...]` at entry, escape every echoed value at exit.
- Connection resolution is delegated to `WP_MCP_AI_Composio_Tools::resolve_connection()`; toolkit allowlists are enforced via `is_toolkit_allowed()`.
- **`connection_id` and `connected_account_id` are different kinds of ID and must never be treated as interchangeable.** `connection_id` is this site's Composio project integration (`conn_...`, from the Remote Site Manager); `connected_account_id` is an end user's authenticated app account (`ca_...`, from Composio). Both are opaque strings, so a swap is easy and used to surface as a bare "connection not found". Any new tool that accepts either must state the distinction in its schema description and route an account ID through `WP_MCP_AI_Composio_Tools::validate_account_id()`; `resolve_connection()` already rejects a `ca_...` passed as `connection_id` with `wp_mcp_ai_composio_id_swapped`.
- Identity resolution is delegated to `WP_MCP_AI_Composio_Tools::resolve_user_id()`. Composio rejects a `connected_account_id` that arrives without its owning `user_id`, so `composio_execute_tool` always sends one: the connection's resolved identity, overridden by the account's own owner when they differ.
- **Account selection goes through `WP_MCP_AI_Composio_Tools::resolve_account_for_toolkit()`.** "First account whose status is `ACTIVE`" is unsafe because Composio's stored status lags a revoked credential. The resolver excludes accounts with a recent `needs_reconnect` verdict, prefers the connection's identity, then probe-verified over unverified, then fresher over staler — and flags `ambiguous` when candidates are indistinguishable.
- **Write-class actions must not resolve ambiguously.** `composio_execute_tool` returns `wp_mcp_ai_composio_ambiguous_account` with the candidate list rather than guessing which mailbox to send from. Read-only actions proceed and report `ambiguous_accounts` instead.
- **Never report health you did not verify.** Surface `health.verification_method` alongside `health.verified`; `status_only` and `probe_inconclusive` are not verification. Present health blocks with `WP_MCP_AI_Composio_Account_Health::present()` so escaping is consistent.
- **Auth failures are recoverable, not fatal.** An auth-class error is rewritten into `wp_mcp_ai_composio_account_auth_required` carrying a `reconnect_url`, and the verdict is recorded so auto-resolution skips the dead account next time.
- `composio_execute_tool` classifies write-class slugs (`DELETE_`, `SEND_`, `CREATE_`, ...) as `destructive` in its response metadata for downstream guardrails, and reports the identity used as `composio_user_id`.
- `composio_manage_accounts` requires an explicit `toolkit` for `prune` so the blast radius is always stated, and re-probes each candidate immediately before deleting it.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-composio-tools.php
vendor/bin/phpunit addons/pro/tests/test-composio-account-health.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — escape rules (always)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro/Base placement rules

## See Also

- Parent folder: [`addons/pro/includes/tools/`](../) — tool class conventions
- Integration core: [`addons/pro/includes/composio/`](../../composio/) — client, health engine, auth handler, webhook controller
