# Compliance & Ethics

## Purpose

Houses 8 law-firm compliance and ethics tools: AI usage disclosure generation, bar deadline monitoring, CLE credit tracking, client confidentiality auditing, data privacy compliance checking, ethics rule checking (ABA Model Rules), malpractice risk scoring, and regulatory change monitoring.

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
| `WP_MCP_AI_Tool_LF_AI_Usage_Disclosure_Generator` | `class-wp-mcp-ai-tool-lf-ai-usage-disclosure-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Bar_Deadline_Monitor` | `class-wp-mcp-ai-tool-lf-bar-deadline-monitor.php` | tool registry |
| `WP_MCP_AI_Tool_LF_CLE_Credit_Tracker` | `class-wp-mcp-ai-tool-lf-cle-credit-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Client_Confidentiality_Auditor` | `class-wp-mcp-ai-tool-lf-client-confidentiality-auditor.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Data_Privacy_Compliance_Checker` | `class-wp-mcp-ai-tool-lf-data-privacy-compliance-checker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Ethics_Rule_Checker` | `class-wp-mcp-ai-tool-lf-ethics-rule-checker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Malpractice_Risk_Scorer` | `class-wp-mcp-ai-tool-lf-malpractice-risk-scorer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Regulatory_Change_Monitor` | `class-wp-mcp-ai-tool-lf-regulatory-change-monitor.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_matter` CPT
- **Writes to:** None (read-only/analysis tools)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** None
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- ABA Model Rules (1.1–7.3) are embedded as a static keyword-to-category map in `WP_MCP_AI_Tool_LF_Ethics_Rule_Checker`.
- Read-only tools carry `cacheable` flag; risk scoring uses configurable multipliers.
- Every tool includes the `DISCLAIMER` constant: *"This is not legal advice. Consult a licensed attorney for specific legal matters."*

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/compliance-ethics/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
