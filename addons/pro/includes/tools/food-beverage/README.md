# Food & Beverage Management

## Purpose

Deterministic Food & Beverage operations toolkit: reads the spec's 17 data
tables (Google Drive Sheets or CSV fixtures), computes the 21 spec metrics
(M-01…M-21) in PHP, and generates the 13 report layouts (R-01…R-13) with
Drafts-folder output only. Built from proposal
`docs/project/proposals/062-food-beverage-management-pro-toolkit-proposal.md`
and the Surf Club Midigama assistant task spec.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | Central Pro tool registry (`enable_fnb_toolkit` toggle) |
| **Optional dependencies** | `google-workspace` toolkit (Drive adapter only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Fnb_Settings` | `class-wp-mcp-ai-fnb-settings.php` | all tools |
| `WP_MCP_AI_Fnb_Data_Source` | `class-wp-mcp-ai-fnb-data-source.php` | all tools |
| `WP_MCP_AI_Fnb_Fixture_Reader` | `class-wp-mcp-ai-fnb-fixture-reader.php` | data source (csv/fixture adapter) |
| `WP_MCP_AI_Fnb_Google_Sheets_Reader` | `class-wp-mcp-ai-fnb-google-sheets-reader.php` | data source (drive adapter) |
| `WP_MCP_AI_Fnb_Metrics` | `class-wp-mcp-ai-fnb-metrics.php` | metric tools |
| `WP_MCP_AI_Fnb_Report_Builder` | `class-wp-mcp-ai-fnb-report-builder.php` | `fnb_generate_report` |
| `WP_MCP_AI_Fnb_Draft_Writer` | `class-wp-mcp-ai-fnb-draft-writer.php` | `fnb_save_draft`, `fnb_list_drafts` |
| `WP_MCP_AI_Fnb_Tool_Base` | `class-wp-mcp-ai-fnb-tool-base.php` | all `fnb_*` tools |
| 21 `WP_MCP_AI_Tool_Fnb_*` tools | `class-wp-mcp-ai-tool-fnb-*.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_fnb_toolkit`),
  `wp_mcp_ai_fnb_settings` (folder IDs, adapter, demo mode), the 17 data
  tables via the configured adapter.
- **Writes to:** Drafts folder only (Drive or local), transients for table
  caching (`wp_mcp_ai_fnb_tables`).
- **Upstream callers:** Pro tool registry, assistants A1–A4, Pro Schedule
  Manager.
- **Downstream collaborators:** `WP_MCP_AI_Pro_Google_Drive_Client`
  (google-workspace), the audit logger.

## Conventions

- Canonical envelope: success array or `WP_Error`; two-gate sanitisation
  (sanitize at entry, escape at exit); `required_capability` on every tool.
- All figures come from the metric engine — no model arithmetic (G-02, G-08).
- ACT-tier actions (send, order, change, image generation, posting) are out
  of this toolkit and off by default for the demo.
- Table IDs follow the spec: 1.0…17.0 plus `assumptions`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/food-beverage/
```

The oracle test reproduces the demo workbook's "Check totals" sheet values.

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md)
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md)
- Proposal: `docs/project/proposals/062-food-beverage-management-pro-toolkit-proposal.md`
- Implementation plan: `docs/project/proposals/062-food-beverage-management-pro-toolkit-implementation-plan.md`
