# Originations

## Purpose

Houses 11 CRE originations tools covering borrower profile analysis, broker relationship tracking, closing checklist management, deal pipeline, deal screening, execution strategy, loan quotes, market comps, volume tracking, rate locks, and term sheet comparison.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Tools registered via the Pro tool registry; `WP_MCP_AI_CRE_Debt_Calculator` for shared math where applicable |
| **Optional dependencies** | `enable_cre_debt_toolkit` setting must be enabled |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_CRE_Borrower_Profile_Analyzer` | `class-wp-mcp-ai-tool-cre-borrower-profile-analyzer.php` | tool registry, orchestrator |
| `WP_MCP_AI_Tool_CRE_Broker_Relationship_Tracker` | `class-wp-mcp-ai-tool-cre-broker-relationship-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Closing_Checklist_Manager` | `class-wp-mcp-ai-tool-cre-closing-checklist-manager.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Deal_Pipeline_Manager` | `class-wp-mcp-ai-tool-cre-deal-pipeline-manager.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Deal_Screening_Calculator` | `class-wp-mcp-ai-tool-cre-deal-screening-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Execution_Strategy_Advisor` | `class-wp-mcp-ai-tool-cre-execution-strategy-advisor.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Loan_Quote_Generator` | `class-wp-mcp-ai-tool-cre-loan-quote-generator.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Market_Comp_Analyzer` | `class-wp-mcp-ai-tool-cre-market-comp-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Origination_Volume_Tracker` | `class-wp-mcp-ai-tool-cre-origination-volume-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Rate_Lock_Manager` | `class-wp-mcp-ai-tool-cre-rate-lock-manager.php` | tool registry |
| `WP_MCP_AI_Tool_CRE_Term_Sheet_Comparator` | `class-wp-mcp-ai-tool-cre-term-sheet-comparator.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (for `enable_cre_debt_toolkit` check)
- **Writes to:** None (read-only/analysis tools); pipeline manager, broker tracker, closing checklist are state-changing
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_CRE_Debt_Calculator` (for quote generation and screening calculations)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Borrower profile scoring uses net worth, liquidity, leverage, experience, and credit metrics per CRE underwriting standards.
- Read-only tools carry `cacheable` flag; state-changing tools carry `write, state-changing`.
- All outputs carry `ANALYSIS ONLY — Not investment advice.` disclaimer.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/cre-debt/originations/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRE Debt toolkit
