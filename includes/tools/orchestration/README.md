# Orchestration Tools

## Purpose

Houses 9 autonomous orchestration MCP tools that manage task plans, session lifecycle, loop health monitoring, capacity planning, exit conditions, and completion detection for agentic workflows.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/pro/includes/tools-init.php` via tool registry |
| **Optional dependencies** | JetEngine (for CCT-based plan storage; falls back to CPT `mcp_task_plan`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Analyze_Loop_Health` | `class-wp-mcp-ai-tool-analyze-loop-health.php` | Autonomous session runner |
| `WP_MCP_AI_Tool_Calculate_Orchestration_Capacity` | `class-wp-mcp-ai-tool-calculate-orchestration-capacity.php` | Orchestration scheduler |
| `WP_MCP_AI_Tool_Check_Exit_Conditions` | `class-wp-mcp-ai-tool-check-exit-conditions.php` | Autonomous loop exit gate |
| `WP_MCP_AI_Tool_Create_Task_Plan` | `class-wp-mcp-ai-tool-create-task-plan.php` | Orchestration workflow initiator |
| `WP_MCP_AI_Tool_Detect_Completion_Indicators` | `class-wp-mcp-ai-tool-detect-completion-indicators.php` | Completion detection |
| `WP_MCP_AI_Tool_Get_Session_Status` | `class-wp-mcp-ai-tool-get-session-status.php` | Session monitoring |
| `WP_MCP_AI_Tool_Get_Task_Plan` | `class-wp-mcp-ai-tool-get-task-plan.php` | Task plan retrieval |
| `WP_MCP_AI_Tool_Manage_Autonomous_Session` | `class-wp-mcp-ai-tool-manage-autonomous-session.php` | Session lifecycle (start/pause/resume/stop) |
| `WP_MCP_AI_Tool_Update_Task_Plan` | `class-wp-mcp-ai-tool-update-task-plan.php` | Task plan progress tracking |

Each class exposes `get_slug()`, `get_required_capability()`, `get_definition()`, and `execute()`. Capabilities: `edit_posts` for mutations, `read` for queries.

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress transients (`mcp_ai_session_*`), CPT `mcp_task_plan` post meta, JetEngine CCT `mcp_task_plans`, `wp_mcp_ai_project_settings` option
- **Writes to:** transients (session state), CPT posts, CCT items, post meta
- **Upstream callers:** `includes/tools/` (tool registry), REST API, autonomous session runner
- **Downstream collaborators:** `includes/orchestration/` (session manager, task plan service), `includes/cct/` (JetEngine integration)
- **Events fired:** none directly
- **Events listened to:** none directly

## Conventions

- Session storage uses WordPress transients with a 24-hour TTL (`mcp_ai_session_{uuid}`).
- Task plans support dual storage: JetEngine CCT (preferred when enabled) with CPT `mcp_task_plan` fallback.
- The dual-condition exit gate requires both completion indicators AND explicit `EXIT_SIGNAL` before a loop can exit gracefully.
- Little's Law (`L = λ × W`) is applied for orchestration capacity calculations.
- All tools return `WP_Error` on failure, canonical success arrays otherwise.

## Tests

```bash
vendor/bin/phpunit tests/test-orchestration-tools.php
```

Coverage targets: session lifecycle, task plan CRUD, loop health analysis, exit condition gating, and capacity calculation.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registration and lifecycle
