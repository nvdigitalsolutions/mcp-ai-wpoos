# Analysis & Compliance

## Purpose

Houses 5 architectural analysis and compliance tools: daylight/solar-gain estimation, natural-ventilation analysis, structural feasibility checking, sustainability-metrics calculation, and building-code compliance verification — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Engine` for shared math and `WP_MCP_AI_Architectural_Codes` for code tables |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Analyze_Daylight_And_Solar_Gain` | `class-wp-mcp-ai-tool-analyze-daylight-and-solar-gain.php` | tool registry |
| `WP_MCP_AI_Tool_Analyze_Natural_Ventilation` | `class-wp-mcp-ai-tool-analyze-natural-ventilation.php` | tool registry |
| `WP_MCP_AI_Tool_Analyze_Structural_Feasibility` | `class-wp-mcp-ai-tool-analyze-structural-feasibility.php` | tool registry |
| `WP_MCP_AI_Tool_Calculate_Sustainability_Metrics` | `class-wp-mcp-ai-tool-calculate-sustainability-metrics.php` | tool registry |
| `WP_MCP_AI_Tool_Check_Building_Code_Compliance` | `class-wp-mcp-ai-tool-check-building-code-compliance.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (toolkit enable flag); toolkit country/zone settings
- **Writes to:** None (read-only analysis tools)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Engine` (seismic/load math); `WP_MCP_AI_Architectural_Codes` (code reference tables); `WP_MCP_AI_Architectural_Sustainability` (metrics baselines)
- **Events fired:** `wp_mcp_ai_arch_after_seismic_calculation`, `wp_mcp_ai_arch_after_compliance_check`
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Country-aware: LK (IS 1893 references), JM (JNBC/ASCE 7 Caribbean), US (ASCE 7-22/IBC/IRC).
- All outputs carry `Analytical / advisory output only.` disclaimer; structural tools reference chartered engineer sign-off.
- Daylight and ventilation tools use simplified CIE/analytical models tagged `cacheable`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/analysis-compliance/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
