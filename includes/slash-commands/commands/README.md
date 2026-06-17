# Slash Command Implementations

## Purpose

Houses 20 slash command implementations that provide in-chat operations — diagnostics, cost tracking, task planning, memory management, content workflow, system status, and tool discovery — all accessible via `/command` syntax in the chat UI.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php` via command registry |
| **Optional dependencies** | `WP_MCP_AI_Cost_Tracking_Service`, `WP_MCP_AI_Async_Health_Monitor`, `WP_MCP_AI_Cron_Status_Service`, `WP_MCP_AI_Tool_Registry`, `WP_MCP_AI_Skill_Registry`, `WP_MCP_AI_Model_Manager`, `WP_MCP_AI_Preset_Manager`, `WP_MCP_AI_Task_Orchestrator`, `WP_MCP_AI_Compactor`, `WP_MCP_AI_Memory_Manager`, `Rank Math` (for `/ship` SEO checks) |

## Public Surface

| Symbol | File | Description |
|---|---|---|
| `WP_MCP_AI_Slash_Command_Clean_Content` | `class-wp-mcp-ai-slash-command-clean-content.php` | Three-phase content quality analysis |
| `WP_MCP_AI_Slash_Command_Compact` | `class-wp-mcp-ai-slash-command-compact.php` | Conversation context compaction |
| `WP_MCP_AI_Slash_Command_Context` | `class-wp-mcp-ai-slash-command-context.php` | Context window management |
| `WP_MCP_AI_Slash_Command_Cost` | `class-wp-mcp-ai-slash-command-cost.php` | Token usage and cost summary |
| `WP_MCP_AI_Slash_Command_Diagnose` | `class-wp-mcp-ai-slash-command-diagnose.php` | Diagnostic bundle generation |
| `WP_MCP_AI_Slash_Command_Help` | `class-wp-mcp-ai-slash-command-help.php` | Command help and discovery |
| `WP_MCP_AI_Slash_Command_Jobs` | `class-wp-mcp-ai-slash-command-jobs.php` | Async job listing and cancellation |
| `WP_MCP_AI_Slash_Command_Markup_Stats` | `class-wp-mcp-ai-slash-command-markup-stats.php` | Markup telemetry counters |
| `WP_MCP_AI_Slash_Command_Memory` | `class-wp-mcp-ai-slash-command-memory.php` | Agent memory inspection and management |
| `WP_MCP_AI_Slash_Command_Model` | `class-wp-mcp-ai-slash-command-model.php` | AI model listing and selection |
| `WP_MCP_AI_Slash_Command_Next_Task` | `class-wp-mcp-ai-slash-command-next-task.php` | Next task recommendation |
| `WP_MCP_AI_Slash_Command_Optimize_Perf` | `class-wp-mcp-ai-slash-command-optimize-perf.php` | Performance optimisation |
| `WP_MCP_AI_Slash_Command_Preset` | `class-wp-mcp-ai-slash-command-preset.php` | Orchestration preset management |
| `WP_MCP_AI_Slash_Command_Session` | `class-wp-mcp-ai-slash-command-session.php` | Session state management |
| `WP_MCP_AI_Slash_Command_Ship` | `class-wp-mcp-ai-slash-command-ship.php` | Pre-publish content readiness checks |
| `WP_MCP_AI_Slash_Command_Skills` | `class-wp-mcp-ai-slash-command-skills.php` | Agent skill pack listing and installation |
| `WP_MCP_AI_Slash_Command_Status` | `class-wp-mcp-ai-slash-command-status.php` | Aggregated system health report |
| `WP_MCP_AI_Slash_Command_Sync_Docs` | `class-wp-mcp-ai-slash-command-sync-docs.php` | Documentation synchronisation |
| `WP_MCP_AI_Slash_Command_Tools` | `class-wp-mcp-ai-slash-command-tools.php` | Tool browsing and inspection |
| `WP_MCP_AI_Slash_Command_Workflow` | `class-wp-mcp-ai-slash-command-workflow.php` | Workflow trigger and management |

All commands expose `execute( $args, $flags, $context )` returning `array|WP_Error`. Guest requests are blocked for sensitive commands.

## Inputs / Outputs / Neighbors

- **Reads from:** various Pro services (cost tracking, async health, cron status, tool registry, skill registry, model manager, preset manager, task orchestrator, compactor, memory manager)
- **Writes to:** agent memory, session state, task plans (via orchestrator services)
- **Upstream callers:** `includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php`
- **Downstream collaborators:** `includes/cost-tracking/`, `includes/orchestration/`, `includes/memory/`, `includes/tools/`, `includes/skills/`
- **Events fired:** none directly
- **Events listened to:** none

## Conventions

- Every command has a unique slug (the part after `/`) matching its class suffix.
- Commands support `--flag=value` and `-f value` syntax. JSON output is available via `--json`.
- Capability gates use `user_can( $user_id, ... )`. Guest requests (`context['guest_request']`) are rejected for authentication-required commands.
- Help text is rendered in Markdown compatible with the chat UI.
- Commands return `array( 'success' => true, 'message' => '...', 'data' => [...] )` or `WP_Error`.

## Tests

```bash
vendor/bin/phpunit tests/test-slash-commands.php
```

Coverage targets: capability gating, guest rejection, help rendering, and flag parsing for each command.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — chat UI integration patterns
