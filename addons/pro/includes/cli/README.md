# Pro CLI

## Purpose

Hosts the Pro-only WP-CLI command handlers (`wp mcp-ai connection`, `task`, `project`, `mcp-server`, `toolkit`, `pro status`) that surface Pro toolkits, remote-site connections, schedule manager, and toolkit MCP servers to operators and CI.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Each command file self-registers via `WP_CLI::add_command( 'mcp-ai …', WP_MCP_AI_Pro_CLI_*_Command::class )`, gated on `defined( 'WP_CLI' ) && WP_CLI`. The bundle is required from [`addons/pro/mcp-ai-wpoos-pro.php`](../../mcp-ai-wpoos-pro.php) at boot when `WP_CLI` is defined |
| **Optional dependencies** | none — every subcommand asserts Pro is loaded (`assert_pro_loaded()`) and the relevant toolkit is enabled (`assert_toolkit_enabled()`) before doing work |

## Public Surface

The contract is the **`wp mcp-ai <subcommand>`** invocation, not the PHP class. Operators script against the CLI command; do not call the classes directly.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Pro_CLI_Base_Command` (abstract) | `class-wp-mcp-ai-pro-cli-base-command.php` | All concrete Pro commands — extends Base's `WP_MCP_AI_CLI_Base_Command`; adds `assert_pro_loaded()` + `assert_toolkit_enabled()` |
| `WP_MCP_AI_Pro_CLI_Connection_Command` → `wp mcp-ai connection` | `class-wp-mcp-ai-pro-cli-connection-command.php` | Remote-site connections: create, CRUD (mesh peers, Shopify, WP) |
| `WP_MCP_AI_Pro_CLI_Project_Command` → `wp mcp-ai project` | `class-wp-mcp-ai-pro-cli-project-command.php` | Project CPT CRUD, update + task plan ops |
| `WP_MCP_AI_Pro_CLI_Task_Command` → `wp mcp-ai task` | `class-wp-mcp-ai-pro-cli-task-command.php` | Task CPT CRUD, update + dependencies + bulk ops |
| `WP_MCP_AI_Pro_CLI_Mcp_Server_Command` → `wp mcp-ai mcp-server` | `class-wp-mcp-ai-pro-cli-mcp-server-command.php` | Per-toolkit MCP servers: list/show/enable/disable/tools |
| `WP_MCP_AI_Pro_CLI_Toolkit_Command` → `wp mcp-ai toolkit` | `class-wp-mcp-ai-pro-cli-toolkit-command.php` | Toolkit enable/disable, status, configuration |
| `WP_MCP_AI_Pro_CLI_Status_Command` → `wp mcp-ai pro status` | `class-wp-mcp-ai-pro-cli-status-command.php` | Pro addon dependency + toolkit health snapshot |

## Inputs / Outputs / Neighbors

- **Reads from:** WP-CLI `$args` / `$assoc_args`; `wp_mcp_ai_settings` option (toolkit flags); the tool registry; Pro CPT/CCT data (projects, tasks, ECAs, …); remote-site manager state; toolkit MCP server registry
- **Writes to:** stdout (formatted via `WP_CLI::log` / `WP_CLI\Utils\format_items`); Pro CPTs and post meta on mutating subcommands; the same options/DB surfaces as the Pro REST controllers
- **Upstream callers:** the `wp` binary (operators, CI, deploy scripts); occasionally other CLI commands chain into these via `WP_CLI::runcommand`
- **Downstream collaborators:** [`includes/tools/`](../tools/) via `WP_MCP_AI_Tool_Registry`, [`addons/pro/includes/services/`](../services/), [`addons/pro/includes/data-stores/`](../data-stores/), [`addons/pro/includes/mcp-servers/`](../mcp-servers/), [`addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php), [`addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`](../class-wp-mcp-ai-pro-schedule-manager.php)
- **Events fired:** none beyond what the underlying tool / service already emits
- **Events listened to:** none — registration is unconditional once `WP_CLI` is defined

## Conventions

Folder-specific deltas:

- Every concrete command extends `WP_MCP_AI_Pro_CLI_Base_Command` (which extends Base's `WP_MCP_AI_CLI_Base_Command`) so progress bars, batch counters, and error/success summaries stay consistent with Base.
- Each file MUST be a no-op outside WP-CLI — guard with `if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }` near the top, and gate `WP_CLI::add_command(…)` on `class_exists( 'WP_CLI' )`.
- Always call `assert_pro_loaded()` first and `assert_toolkit_enabled( $setting_key, $label )` before invoking toolkit-scoped operations — the CLI must not surface tools whose toolkit is disabled in settings.
- Output goes through `WP_CLI::log` / `WP_CLI::success` / `WP_CLI::warning` / `WP_CLI::error` — never `echo` or `print_r`.
- Mutating subcommands MUST honour the same capability checks as their REST/tool counterparts — the CLI is not an authentication bypass.
- Long-running subcommands SHOULD accept `--dry-run` and `--batch-size=` flags where applicable; mirror the shapes used by Base's `WP_MCP_AI_CLI_Bulk_Command`.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-wp-cli-pro-commands.php
vendor/bin/phpunit addons/pro/tests/test-pro-cli-mcp-server-command.php
```

Tests load command files in isolation (no WP-CLI bootstrap) and assert against argument-parsing, capability gating, and command metadata. Deeper end-to-end coverage of the underlying tools/services lives in their own suites.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability + sanitisation rules (always)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — for `wp mcp-ai tool …` / `mcp-server tools`
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — what belongs in Pro CLI vs Base CLI
- [`.context/testing.md`](../../../../.context/testing.md) — running CLI tests
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat + two-gate sanitisation rules

## See Also

- Base counterpart: [`includes/cli/`](../../../../includes/cli/) — `wp mcp-ai assistant|bulk|content|credential|dlq|log|measurement|settings|sla|slash|tool`
- Sibling surfaces: [`addons/pro/includes/rest/`](../rest/), [`addons/pro/includes/slash-commands/`](../slash-commands/) — many Pro CLI commands have chat-side slash-command and REST counterparts
- Collaborators: [`addons/pro/includes/mcp-servers/`](../mcp-servers/), [`addons/pro/includes/services/`](../services/), [`addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php)
