# Asset Management

## Purpose

Houses 12 CRE asset management tools covering disposition analysis, CapEx planning, hold/sell decisions, lease expiration, loan modifications, surveillance, budgeting, performance tracking, servicing fees, tenant credit, watchlists, and workout scenario modelling — all backed by the shared `WP_MCP_AI_CRE_Debt_Calculator`.

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
| `WP_MCP_AI_Tool_CRE_Asset_Disposition_Analyzer` | `class-wp-mcp-ai-tool-cre-asset-disposition-analyzer.php` | tool registry, orchestrator |
| `WP_MCP_AI_Tool_CRE_Capex_Reserve_Planner` | `class-wp-mcp-ai-tool-cre-capex-reserve-planner.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Hold_Sell_Analyzer` | `class-wp-mcp-ai-tool-cre-hold-sell-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Lease_Expiration_Manager` | `class-wp-mcp-ai-tool-cre-lease-expiration-manager.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Loan_Modification_Calculator` | `class-wp-mcp-ai-tool-cre-loan-modification-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Loan_Surveillance_Dashboard` | `class-wp-mcp-ai-tool-cre-loan-surveillance-dashboard.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Property_Budget_Manager` | `class-wp-mcp-ai-tool-cre-property-budget-manager.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Property_Performance_Tracker` | `class-wp-mcp-ai-tool-cre-property-performance-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Servicing_Fee_Calculator` | `class-wp-mcp-ai-tool-cre-servicing-fee-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Tenant_Credit_Analyzer` | `class-wp-mcp-ai-tool-cre-tenant-credit-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Watchlist_Manager` | `class-wp-mcp-ai-tool-cre-watchlist-manager.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Workout_Scenario_Modeler` | `class-wp-mcp-ai-tool-cre-workout-scenario-modeler.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (for `enable_cre_debt_toolkit` check)
- **Writes to:** None (read-only/analysis tools); watchlist and budget manager are state-changing
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_CRE_Debt_Calculator` (shared financial math)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Shared financial math delegated to `WP_MCP_AI_CRE_Debt_Calculator` (curve interpolation, format helpers).
- Read-only tools carry `cacheable` flag; state-changing tools carry `write, state-changing`.
- All outputs carry `ANALYSIS ONLY — Not investment advice.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/cre-debt/asset-management/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRE Debt toolkit
