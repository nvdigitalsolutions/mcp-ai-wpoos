# Project Delivery

## Purpose

Houses 3 project delivery tools: BIM Execution Plan generation (aligned with AIA E202/E203 and ISO 19650-2), RFI (Request for Information) log management, and submittal log management — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Interop` for BEP section catalog |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Generate_Bim_Execution_Plan` | `class-wp-mcp-ai-tool-generate-bim-execution-plan.php` | tool registry |
| `WP_MCP_AI_Tool_Manage_Rfi_Log` | `class-wp-mcp-ai-tool-manage-rfi-log.php` | tool registry |
| `WP_MCP_AI_Tool_Manage_Submittal_Log` | `class-wp-mcp-ai-tool-manage-submittal-log.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`; project metadata, country code, BIM standards selections
- **Writes to:** BEP generates structured section catalog + markdown; RFI/submittal logs persist to CPT
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Interop` (BEP section catalog)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- BEP tool is `read-only` and `cacheable`; RFI/submittal tools are `state-changing`.
- BEP output includes both structured JSON sections (for LLM fill-in) and a rendered markdown document.
- Supports BIM standards: ISO 19650-2, AIA E203, BS EN ISO 19650-1.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/project-delivery/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
