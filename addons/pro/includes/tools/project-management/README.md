# Project Management

## Purpose

Houses 8 project management tools: 7 PARA-method tools (classify items into Projects/Areas/Resources/Archives buckets, create/list/update areas, move items to archives, promote resources to projects, weekly review) and 1 MemPalace decision-capture tool for recording project decisions, status updates, and ADRs with core-tier importance.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_PARA_Taxonomy` for PARA classification; `WP_MCP_AI_Pro_Capture_Tool_Base` for decision capture |
| **Optional dependencies** | PARA taxonomy must be enabled (`WP_MCP_AI_PARA_Taxonomy::is_enabled()`); MemPalace wing for decisions |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_PARA_Classify_Item` | `class-wp-mcp-ai-tool-para-classify-item.php` | tool registry |
| `WP_MCP_AI_Tool_PARA_Create_Area` | `class-wp-mcp-ai-tool-para-create-area.php` | tool registry |
| `WP_MCP_AI_Tool_PARA_List_Areas` | `class-wp-mcp-ai-tool-para-list-areas.php` | tool registry |
| `WP_MCP_AI_Tool_PARA_Move_To_Archives` | `class-wp-mcp-ai-tool-para-move-to-archives.php` | tool registry |
| `WP_MCP_AI_Tool_PARA_Promote_Resource_To_Project` | `class-wp-mcp-ai-tool-para-promote-resource-to-project.php` | tool registry |
| `WP_MCP_AI_Tool_PARA_Update_Area` | `class-wp-mcp-ai-tool-para-update-area.php` | tool registry |
| `WP_MCP_AI_Tool_PARA_Weekly_Review` | `class-wp-mcp-ai-tool-para-weekly-review.php` | tool registry |
| `WP_MCP_AI_Tool_PM_Capture_Decision` | `class-wp-mcp-ai-tool-pm-capture-decision.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress posts (any supported type for PARA classification); PARA taxonomy terms; MemPalace wing data
- **Writes to:** PARA taxonomy assignments (`WP_MCP_AI_PARA_Taxonomy::assign()`); MemPalace `project/{id}` wing with `tier=core`, `importance=0.85`
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_PARA_Taxonomy` (bucket assignment, root validation); `WP_MCP_AI_Memory_Capture_Service` (decision storage); `WP_MCP_AI_Pro_Capture_Tool_Base` (base class for capture tools)
- **Events fired:** None explicit
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- PARA tools carry `pro`, `write`, and `state-changing` flags.
- Bucket root slugs are locked to: `projects`, `areas`, `resources`, `archives`.
- Decision capture extends `WP_MCP_AI_Pro_Capture_Tool_Base` and defaults to `tier=core` with 5-year TTL.
- PARA taxonomy availability is gated by `WP_MCP_AI_PARA_Taxonomy::is_enabled()`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/project-management/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
