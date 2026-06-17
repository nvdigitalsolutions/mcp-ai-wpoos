# Billing & Trust

## Purpose

Houses 10 law-firm billing and trust accounting tools: accounts receivable tracking, billing compliance, expense reimbursement, fee calculation, invoice generation (standard + LEDES), profitability analysis, retainer balance monitoring, time entry recording, trust account management, and trust reconciliation — all gated on `enable_law_firm_toolkit`.

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
| `WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker` | `class-wp-mcp-ai-tool-lf-accounts-receivable-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Billing_Compliance_Checker` | `class-wp-mcp-ai-tool-lf-billing-compliance-checker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker` | `class-wp-mcp-ai-tool-lf-expense-reimbursement-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Fee_Calculator` | `class-wp-mcp-ai-tool-lf-fee-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Invoice_Generator` | `class-wp-mcp-ai-tool-lf-invoice-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Profitability_Analyzer` | `class-wp-mcp-ai-tool-lf-profitability-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Retainer_Balance_Monitor` | `class-wp-mcp-ai-tool-lf-retainer-balance-monitor.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Time_Entry_Recorder` | `class-wp-mcp-ai-tool-lf-time-entry-recorder.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Trust_Account_Manager` | `class-wp-mcp-ai-tool-lf-trust-account-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool` | `class-wp-mcp-ai-tool-lf-trust-reconciliation-tool.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_time_entry`, `mcp_ai_lf_matter` CPTs
- **Writes to:** `mcp_ai_lf_matter` meta (`_lf_expenses`, `_lf_invoices`), time entries
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Law_Firm_Calculator` (format_currency, format_ledes_line, present-value math)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- IOLTA compliance: trust tools are read-only or require `manage_options`.
- Billing tools delegate financial formatting to `WP_MCP_AI_Law_Firm_Calculator`.
- LEDES-formatted invoices are supported via `format_ledes_line()`.
- Every tool includes the `DISCLAIMER` constant: *"This is not legal advice."*

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/billing-trust/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
