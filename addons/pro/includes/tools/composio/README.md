# Composio Tools

## Purpose

The six `composio_*` MCP tools that expose Composio Connect to NV oOS assistants: catalog discovery, schema lookup, connected-account listing, Connect Link creation, tool execution and trigger management.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/composio/composio-init.php` (priority 45 on `wp_mcp_ai_bootstrapped`) |
| **Optional dependencies** | none — every tool degrades to a clear `WP_Error` when no enabled `composio` connection exists |

## Public Surface

| Class | Slug | Capability |
|---|---|---|
| `WP_MCP_AI_Tool_Composio_List_Tools` | `composio_list_tools` | `edit_posts` |
| `WP_MCP_AI_Tool_Composio_Get_Tool_Schema` | `composio_get_tool_schema` | `edit_posts` |
| `WP_MCP_AI_Tool_Composio_List_Connected_Accounts` | `composio_list_connected_accounts` | `manage_options` |
| `WP_MCP_AI_Tool_Composio_Create_Connect_Link` | `composio_create_connect_link` | `manage_options` |
| `WP_MCP_AI_Tool_Composio_Execute_Tool` | `composio_execute_tool` | `manage_options` |
| `WP_MCP_AI_Tool_Composio_Manage_Triggers` | `composio_manage_triggers` | `manage_options` |
| `WP_MCP_AI_Composio_Tools` | (static helper — not a tool) | — |

## Inputs / Outputs / Neighbors

- **Reads from:** Remote Site Manager `composio` connections; Composio API v3.1 via `WP_MCP_AI_Composio_Client`.
- **Writes to:** Composio connected accounts, tool executions and trigger instances (write-class tools only); `wp_mcp_ai_composio_tool_executed` action on execution.
- **Upstream callers:** tool registry, chat orchestrator.
- **Downstream collaborators:** [`WP_MCP_AI_Composio_Client`](../../composio/class-wp-mcp-ai-composio-client.php), [`WP_MCP_AI_Composio_Auth_Handler`](../../composio/class-wp-mcp-ai-composio-auth-handler.php).
- **Events fired:** `wp_mcp_ai_composio_tool_executed`.
- **Events listened to:** none.

## Conventions

- Canonical envelope (`format_success_response()` via `WP_MCP_AI_Tool_Envelope`) — success arrays only, failures are `WP_Error`.
- Two-gate sanitisation: sanitize `$arguments[...]` at entry, escape every echoed value at exit.
- Connection resolution is delegated to `WP_MCP_AI_Composio_Tools::resolve_connection()`; toolkit allowlists are enforced via `is_toolkit_allowed()`.
- `composio_execute_tool` classifies write-class slugs (`DELETE_`, `SEND_`, `CREATE_`, ...) as `destructive` in its response metadata for downstream guardrails.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-composio-tools.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — escape rules (always)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro/Base placement rules

## See Also

- Parent folder: [`addons/pro/includes/tools/`](../) — tool class conventions
- Integration core: [`addons/pro/includes/composio/`](../../composio/) — client, auth handler, webhook controller
