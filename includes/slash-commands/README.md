# Slash Commands

## Purpose

Parses, validates, routes, executes, and audits in-chat `/command` invocations (`/help`, `/ship`, `/compact`, `/context`, `/cost`, `/diagnose`, `/jobs`, `/memory`, `/model`, `/preset`, `/session`, `/skills`, `/status`, `/tools`, `/workflow`, …) — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/slash-commands/slash-commands-init.php`](./slash-commands-init.php) — pulled in from `includes/bootstrap/loader.php` |
| **Optional dependencies** | none — individual commands degrade gracefully when subsystems they query (cron, memory, harness, …) are absent |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Slash_Command_Handler` | `class-wp-mcp-ai-slash-command-handler.php` | chat REST controller, Pro chat surfaces |
| `WP_MCP_AI_Slash_Command_Parser` | `class-wp-mcp-ai-slash-command-parser.php` | handler (tokenizes `/cmd arg=value …` into a normalized struct) |
| `WP_MCP_AI_Slash_Command_Validator` | `class-wp-mcp-ai-slash-command-validator.php` | handler (capability + nonce + rate-limit checks) |
| `WP_MCP_AI_Slash_Command_Audit` | `class-wp-mcp-ai-slash-command-audit.php` | handler (persistent audit table + cron cleanup) |
| `WP_MCP_AI_Slash_Command_Workflow_Orchestrator` | `class-wp-mcp-ai-slash-command-workflow-orchestrator.php` | `/workflow` and chained commands |
| `WP_MCP_AI_Slash_Command_Performance_Optimizer` | `class-wp-mcp-ai-slash-command-performance-optimizer.php` | `/optimize-perf`, performance hints elsewhere |
| `WP_MCP_AI_Slash_Command_Toolkit_Manager` | `class-wp-mcp-ai-slash-command-toolkit-manager.php` | dynamic toolkit-defined command registration |
| `WP_MCP_AI_Slash_Command_*` (one class per `commands/class-wp-mcp-ai-slash-command-*.php`) | `commands/` | registered by handler at boot — not called directly |
| `wp_mcp_ai_get_slash_command_handler()`, `wp_mcp_ai_execute_slash_command()`, `wp_mcp_ai_register_slash_command()`, `wp_mcp_ai_slash_command_exists()`, `wp_mcp_ai_get_slash_commands()`, `wp_mcp_ai_execute_workflow()` | `slash-commands-init.php` | the procedural API every external caller should use |

## Inputs / Outputs / Neighbors

- **Reads from:** chat REST input (message body for `/cmd …`), assistant CPT meta (context for `/preset`, `/skills`, `/model`), tool registry, memory CCT, cron schedule, performance counters.
- **Writes to:** custom audit table (created on boot — see `wp_mcp_ai_create_slash_command_audit_table()`), chat transcript via the handler, plugin options when a command mutates preferences (`/model`, `/preset`).
- **Upstream callers:** [`includes/rest/`](../rest/) (`class-wp-mcp-ai-rest-slash-command-controller.php`), the chat REST controller, Pro chat surfaces, [`assets/js/chat.js`](../../assets/js/chat.js) (autocomplete + dispatch).
- **Downstream collaborators:** [`includes/tools/`](../tools/) (most commands dispatch into tools), [`includes/services/`](../services/) (chat + cron services), [`includes/measurement/`](../measurement/) (`/cost`, `/status`, `/diagnose`), [`includes/harness/`](../harness/) (`/memory`, `/skills`), [`includes/markup/`](../markup/) (`/markup-stats`, `/clean-content`).
- **Events fired:** `wp_mcp_ai_register_slash_commands` (allows add-ons to register commands), per-command `wp_mcp_ai_slash_command_executed` audit action, `wp_mcp_ai_cleanup_slash_audit` cron event.
- **Events listened to:** plugin activation (audit-table install), WP-Cron cleanup, `init` for command registration, `wp_mcp_ai_register_slash_commands` for toolkit-defined commands.

## Conventions

- **Every command class belongs in `commands/`**. The top-level files in this folder are protocol infrastructure (handler, parser, validator, audit, orchestrator, optimizer, toolkit manager) — not individual commands.
- The handler is the **only** entry point. Never invoke a command class directly from REST or JS; route through `wp_mcp_ai_execute_slash_command()` so validation, rate-limiting, and audit logging are uniform.
- Each command must declare a capability requirement and return a structured payload (`{ "type": "…", "data": …, "render": "…" }`) — never echo HTML directly. The chat UI handles rendering.
- New commands must register through the `wp_mcp_ai_register_slash_commands` filter or `wp_mcp_ai_register_slash_command()` — do not edit `wp_mcp_ai_load_default_slash_commands()` to bolt one on.
- The audit table is created lazily on activation/upgrade; never assume it exists in tests — gate on `WP_MCP_AI_Slash_Command_Audit::table_exists()` first.

## Tests

```bash
vendor/bin/phpunit tests/test-slash-command-help.php
vendor/bin/phpunit tests/test-slash-command-ship.php
vendor/bin/phpunit tests/test-slash-command-compact.php
vendor/bin/phpunit tests/test-slash-command-context.php
vendor/bin/phpunit tests/test-slash-command-cost.php
vendor/bin/phpunit tests/test-slash-command-diagnose.php
vendor/bin/phpunit tests/test-slash-command-jobs.php
vendor/bin/phpunit tests/test-slash-command-memory.php
vendor/bin/phpunit tests/test-slash-command-model.php
vendor/bin/phpunit tests/test-slash-command-preset.php
vendor/bin/phpunit tests/test-slash-command-session.php
vendor/bin/phpunit tests/test-slash-command-skills.php
vendor/bin/phpunit tests/test-slash-command-status.php
vendor/bin/phpunit tests/test-slash-command-tools.php
vendor/bin/phpunit tests/test-slash-command-workflow-dependencies.php
vendor/bin/phpunit tests/test-slash-command-workflow-conditional.php
vendor/bin/phpunit tests/test-slash-command-workflow-enhancements.php
vendor/bin/phpunit tests/test-slash-command-error-handling.php
vendor/bin/phpunit tests/test-slash-command-chat-integration.php
vendor/bin/phpunit tests/test-slash-command-url-construction.php
vendor/bin/phpunit tests/rest/test-rest-slash-command-controller.php
```

Manual / browser-driven smoke tests live in [`tests/manual/test-slash-commands-*.html`](../../tests/manual/).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + nonce rules for chat actions (always)
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — how the front-end parses and renders `/cmd` results
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — most commands wrap a tool
- [`CLAUDE.md`](../../CLAUDE.md) — canonical tool envelope (commands return the same shape)

## See Also

- Sibling: [`tools/`](../tools/) — the registry every command ultimately invokes
- Sibling: [`rest/`](../rest/) — the slash-command REST controller
- Sibling: [`measurement/`](../measurement/) — backs `/cost`, `/diagnose`, `/status`
- Frontend: [`assets/js/chat.js`](../../assets/js/chat.js) — autocomplete + dispatch
