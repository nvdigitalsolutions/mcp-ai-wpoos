# Litigation Support

## Purpose

Houses 8 law-firm litigation support tools: damages calculation (economic/non-economic/punitive with present value), deposition summary generation, eDiscovery document analysis, evidence catalog management, expert witness tracking, jury instruction drafting, settlement value calculation, and trial preparation checklists.

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
| `WP_MCP_AI_Tool_LF_Damages_Calculator` | `class-wp-mcp-ai-tool-lf-damages-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Deposition_Summary_Generator` | `class-wp-mcp-ai-tool-lf-deposition-summary-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_EDiscovery_Document_Analyzer` | `class-wp-mcp-ai-tool-lf-ediscovery-document-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Evidence_Catalog_Manager` | `class-wp-mcp-ai-tool-lf-evidence-catalog-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Expert_Witness_Tracker` | `class-wp-mcp-ai-tool-lf-expert-witness-tracker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Jury_Instruction_Drafter` | `class-wp-mcp-ai-tool-lf-jury-instruction-drafter.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Settlement_Value_Calculator` | `class-wp-mcp-ai-tool-lf-settlement-value-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Trial_Preparation_Checklist` | `class-wp-mcp-ai-tool-lf-trial-preparation-checklist.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_matter` CPT
- **Writes to:** None (read-only/analysis tools, except state-changing checklist/evidence tools)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Law_Firm_Calculator` (present value, damages schedule, currency formatting)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- `WP_MCP_AI_Tool_LF_Damages_Calculator` requires the parent `class-wp-mcp-ai-law-firm-calculator.php` at construction.
- Damages calculations use configurable discount rate (0–20%), wage growth rate (0–10%), and pain multiplier (1–10).
- Every tool carries the `DISCLAIMER` constant.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/litigation-support/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
