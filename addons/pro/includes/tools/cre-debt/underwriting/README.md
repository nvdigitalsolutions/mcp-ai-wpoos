# Underwriting

## Purpose

Houses 13 CRE underwriting tools covering amortization scheduling, cap rate sensitivity, DCF modelling, debt yield analysis, environmental risk scoring, leverage/return analysis, loan sizing, NOI calculation, operating expense benchmarking, property valuation, rent roll analysis, stress testing, and underwriting memo generation — all backed by the shared `WP_MCP_AI_CRE_Debt_Calculator`.

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
| `WP_MCP_AI_Tool_CRE_Amortization_Scheduler` | `class-wp-mcp-ai-tool-cre-amortization-scheduler.php` | tool registry, orchestrator |
| `WP_MCP_AI_Tool_CRE_Cap_Rate_Sensitivity` | `class-wp-mcp-ai-tool-cre-cap-rate-sensitivity.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_DCF_Modeler` | `class-wp-mcp-ai-tool-cre-dcf-modeler.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Debt_Yield_Analyzer` | `class-wp-mcp-ai-tool-cre-debt-yield-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Environmental_Risk_Scorer` | `class-wp-mcp-ai-tool-cre-environmental-risk-scorer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Leverage_Return_Analyzer` | `class-wp-mcp-ai-tool-cre-leverage-return-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Loan_Sizer` | `class-wp-mcp-ai-tool-cre-loan-sizer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_NOI_Calculator` | `class-wp-mcp-ai-tool-cre-noi-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Operating_Expense_Benchmarker` | `class-wp-mcp-ai-tool-cre-operating-expense-benchmarker.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Property_Valuation_Engine` | `class-wp-mcp-ai-tool-cre-property-valuation-engine.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Rent_Roll_Analyzer` | `class-wp-mcp-ai-tool-cre-rent-roll-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Stress_Test_Modeler` | `class-wp-mcp-ai-tool-cre-stress-test-modeler.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Underwriting_Memo_Generator` | `class-wp-mcp-ai-tool-cre-underwriting-memo-generator.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (for `enable_cre_debt_toolkit` check)
- **Writes to:** None (all tools are read-only/analysis)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_CRE_Debt_Calculator` (amortization schedules, DCF/IRR/NPV, DSCR/LTV/debt yield, loan sizing, defeasance/yield maintenance)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Amortization supports IO periods, balloon maturity, and optional prepayment analysis (defeasance or yield maintenance).
- All tools are read-only with `cacheable` flag.
- All outputs carry `ANALYSIS ONLY — Not investment advice.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/cre-debt/underwriting/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRE Debt toolkit
