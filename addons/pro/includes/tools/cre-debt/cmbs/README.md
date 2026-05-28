# CMBS / Securitization

## Purpose

Houses 10 CMBS and CRE CLO tools covering bond cash flow modelling, deal structuring, defeasance, investor reporting, maturity risk, pool analysis, rating agency analysis, special servicing, surveillance, and CLO modelling — all backed by the shared `WP_MCP_AI_CRE_Debt_Calculator`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/tools/cre-debt/class-wp-mcp-ai-cre-debt-calculator.php` (shared calculator); tools registered via the Pro tool registry |
| **Optional dependencies** | `enable_cre_debt_toolkit` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_CMBS_Bond_Cash_Flow_Modeler` | `class-wp-mcp-ai-tool-cmbs-bond-cash-flow-modeler.php` | tool registry, orchestrator |
| `WP_MCP_AI_Tool_CMBS_Deal_Structurer` | `class-wp-mcp-ai-tool-cmbs-deal-structurer.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Defeasance_Calculator` | `class-wp-mcp-ai-tool-cmbs-defeasance-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Investor_Reporting_Generator` | `class-wp-mcp-ai-tool-cmbs-investor-reporting-generator.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Maturity_Risk_Analyzer` | `class-wp-mcp-ai-tool-cmbs-maturity-risk-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Pool_Analyzer` | `class-wp-mcp-ai-tool-cmbs-pool-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Rating_Agency_Analyzer` | `class-wp-mcp-ai-tool-cmbs-rating-agency-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Special_Servicing_Tracker` | `class-wp-mcp-ai-tool-cmbs-special-servicing-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_CMBS_Surveillance_Monitor` | `class-wp-mcp-ai-tool-cmbs-surveillance-monitor.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_CLO_Modeler` | `class-wp-mcp-ai-tool-cre-clo-modeler.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (for `enable_cre_debt_toolkit` check)
- **Writes to:** None (read-only/analysis tools); special servicing tracker is state-changing
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_CRE_Debt_Calculator` (CDR/CPR math, loss severity, waterfall, NPV/IRR)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- CMBS/CDR/CPR/loss-severity methodology per CREFC / SIFMA conventions.
- Read-only tools carry `cacheable` flag; `cmbs_special_servicing_tracker` carries `write, state-changing`.
- All outputs carry `ANALYSIS ONLY — Not investment advice.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/cre-debt/cmbs/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRE Debt toolkit
