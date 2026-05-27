# Matter Management

## Purpose

Houses 10 law-firm matter management tools: calendar rule calculation, case outcome prediction, case status dashboard, case timeline generation, court deadline tracking, matter budget management, matter pipeline management, opposing counsel tracking, statute of limitations calculation, and task assignment management.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry (law-firm module) |
| **Optional dependencies** | `enable_law_firm_toolkit` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator` | `class-wp-mcp-ai-tool-lf-calendar-rule-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Case_Outcome_Predictor` | `class-wp-mcp-ai-tool-lf-case-outcome-predictor.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Case_Status_Dashboard` | `class-wp-mcp-ai-tool-lf-case-status-dashboard.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Case_Timeline_Generator` | `class-wp-mcp-ai-tool-lf-case-timeline-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Court_Deadline_Tracker` | `class-wp-mcp-ai-tool-lf-court-deadline-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Matter_Budget_Manager` | `class-wp-mcp-ai-tool-lf-matter-budget-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Matter_Pipeline_Manager` | `class-wp-mcp-ai-tool-lf-matter-pipeline-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Opposing_Counsel_Tracker` | `class-wp-mcp-ai-tool-lf-opposing-counsel-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator` | `class-wp-mcp-ai-tool-lf-statute-of-limitations-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Task_Assignment_Manager` | `class-wp-mcp-ai-tool-lf-task-assignment-manager.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_matter` CPT (post meta: `_lf_status`, `_lf_practice_area`, `_lf_deadlines`)
- **Writes to:** `mcp_ai_lf_matter` CPT (status updates, deadlines, budgets)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Law_Firm_Calculator`, billing/time-entry tools
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- `WP_MCP_AI_Tool_LF_Case_Status_Dashboard` aggregates matters by status, practice area, and upcoming deadlines (filterable by week/month/quarter/year).
- Statute of limitations calculation supports jurisdiction-specific tolling rules.
- Every tool carries the `DISCLAIMER` constant.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/matter-management/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
