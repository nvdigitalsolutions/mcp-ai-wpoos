# Estimation & Scheduling

## Purpose

Houses 5 architectural cost estimation and project scheduling tools: construction cost estimation (per-country rate tables), Bill of Quantities generation (POMI/SMM7/CSI formats), construction timeline scheduling, material schedule generation, and value-engineering option proposals — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Engine` for cost-rate and currency tables |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Estimate_Construction_Cost` | `class-wp-mcp-ai-tool-estimate-construction-cost.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Bill_Of_Quantities` | `class-wp-mcp-ai-tool-generate-bill-of-quantities.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Construction_Timeline` | `class-wp-mcp-ai-tool-generate-construction-timeline.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Material_Schedule` | `class-wp-mcp-ai-tool-generate-material-schedule.php` | tool registry |
| `WP_MCP_AI_Tool_Propose_Value_Engineering_Options` | `class-wp-mcp-ai-tool-propose-value-engineering-options.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings`; floor plan / area / country inputs from calling agent; `wp_mcp_ai_arch_location_factor` filter
- **Writes to:** None (analysis/generation tools); fires `wp_mcp_ai_arch_after_cost_estimate` and `wp_mcp_ai_arch_boq_generated` actions
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Engine` (cost rates, currency conversion, unit math); `WP_MCP_AI_Architectural_Sustainability` (BoQ format catalog)
- **Events fired:** `wp_mcp_ai_arch_after_cost_estimate`, `wp_mcp_ai_arch_boq_generated`
- **Events listened to:** `wp_mcp_ai_arch_location_factor` (cost-engine filter)

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Country-aware rate tables: LK (LKR, POMI), JM (JMD, SMM7/NRM2), US (USD, CSI MasterFormat 2020).
- Cost estimates carry `non-deterministic` and `model-dependent` flags; BoQ is `cacheable`.
- All outputs carry `Indicative / advisory only — confirm with quantity surveyor / cost engineer.` disclaimer.
- Currency conversion uses `WP_MCP_AI_Architectural_Engine::convert_currency()`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/estimation-scheduling/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
