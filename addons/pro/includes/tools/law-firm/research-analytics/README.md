# Research & Analytics

## Purpose

Houses 8 law-firm research and analytics tools: attorney utilization tracking, case law analysis (holdings/reasoning/dissent/impact + comparisons), client satisfaction analysis, competitive benchmarking, firm performance dashboards, legal research assistance, matter analytics generation, and revenue forecasting.

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
| `WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker` | `class-wp-mcp-ai-tool-lf-attorney-utilization-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Case_Law_Analyzer` | `class-wp-mcp-ai-tool-lf-case-law-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Client_Satisfaction_Analyzer` | `class-wp-mcp-ai-tool-lf-client-satisfaction-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Competitive_Benchmarker` | `class-wp-mcp-ai-tool-lf-competitive-benchmarker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard` | `class-wp-mcp-ai-tool-lf-firm-performance-dashboard.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Legal_Research_Assistant` | `class-wp-mcp-ai-tool-lf-legal-research-assistant.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Matter_Analytics_Generator` | `class-wp-mcp-ai-tool-lf-matter-analytics-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Revenue_Forecaster` | `class-wp-mcp-ai-tool-lf-revenue-forecaster.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_matter` CPT (with meta: `_lf_case_citation`, `_lf_holding`, `_lf_reasoning`, `_lf_cited_cases`)
- **Writes to:** None (read-only/analysis tools)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** None
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- `WP_MCP_AI_Tool_LF_Case_Law_Analyzer` supports 5 analysis types: holding, reasoning, dissent, impact, all — and cross-case comparison.
- Case law is stored in `mcp_ai_lf_matter` CPT with citation, holding, reasoning, and dissent meta fields.
- Read-only tools carry `cacheable` flag.
- Every tool carries the `DISCLAIMER` constant.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/research-analytics/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
