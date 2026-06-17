# Debt Fund Management

## Purpose

Houses 11 debt fund management tools covering concentration limits, covenant compliance, credit risk scoring, debt waterfalls, capital calls, liquidity analysis, portfolio dashboards, fund returns, scenario modelling, LP reporting, and warehouse line management — all backed by the shared `WP_MCP_AI_CRE_Debt_Calculator`.

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
| `WP_MCP_AI_Tool_CRE_Concentration_Limit_Monitor` | `class-wp-mcp-ai-tool-cre-concentration-limit-monitor.php` | tool registry, orchestrator |
| `WP_MCP_AI_Tool_CRE_Covenant_Compliance_Checker` | `class-wp-mcp-ai-tool-cre-covenant-compliance-checker.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Credit_Risk_Scorer` | `class-wp-mcp-ai-tool-cre-credit-risk-scorer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Debt_Waterfall_Modeler` | `class-wp-mcp-ai-tool-cre-debt-waterfall-modeler.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Fund_Capital_Call_Calculator` | `class-wp-mcp-ai-tool-cre-fund-capital-call-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Fund_Liquidity_Analyzer` | `class-wp-mcp-ai-tool-cre-fund-liquidity-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Fund_Portfolio_Dashboard` | `class-wp-mcp-ai-tool-cre-fund-portfolio-dashboard.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Fund_Return_Calculator` | `class-wp-mcp-ai-tool-cre-fund-return-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Fund_Scenario_Modeler` | `class-wp-mcp-ai-tool-cre-fund-scenario-modeler.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_LP_Report_Generator` | `class-wp-mcp-ai-tool-cre-lp-report-generator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Warehouse_Line_Manager` | `class-wp-mcp-ai-tool-cre-warehouse-line-manager.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (for `enable_cre_debt_toolkit` check)
- **Writes to:** None (read-only/analysis tools); warehouse line manager is state-changing
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_CRE_Debt_Calculator` (waterfall distributions, IRR/return math)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Concentration limit formulas against configurable policy limits with breach/warning classification.
- Read-only tools carry `cacheable` flag; `cre_warehouse_line_manager` carries `write, state-changing`.
- All outputs carry `ANALYSIS ONLY — Not investment advice.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/cre-debt/debt-fund/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRE Debt toolkit
