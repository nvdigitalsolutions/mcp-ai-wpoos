# Intake Management

## Purpose

Houses 8 law-firm client intake tools: client communication logging, intake form processing (creates `mcp_ai_lf_client` CPT records), client portal management, client profile analysis, conflict-of-interest checking, engagement letter generation, lead scoring, and referral source tracking.

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
| `WP_MCP_AI_Tool_LF_Client_Communication_Logger` | `class-wp-mcp-ai-tool-lf-client-communication-logger.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Client_Intake_Processor` | `class-wp-mcp-ai-tool-lf-client-intake-processor.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Client_Portal_Manager` | `class-wp-mcp-ai-tool-lf-client-portal-manager.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Client_Profile_Analyzer` | `class-wp-mcp-ai-tool-lf-client-profile-analyzer.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Conflict_Of_Interest_Checker` | `class-wp-mcp-ai-tool-lf-conflict-of-interest-checker.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Engagement_Letter_Generator` | `class-wp-mcp-ai-tool-lf-engagement-letter-generator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Lead_Scoring_Calculator` | `class-wp-mcp-ai-tool-lf-lead-scoring-calculator.php` | tool registry |
| `WP_MCP_AI_Tool_LF_Referral_Source_Tracker` | `class-wp-mcp-ai-tool-lf-referral-source-tracker.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_law_firm_toolkit`), `mcp_ai_lf_client` CPT
- **Writes to:** `mcp_ai_lf_client` CPT (client records with meta: `_lf_email`, `_lf_phone`, `_lf_practice_area`, `_lf_urgency`, `_lf_status`)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** Matter management tools (client→matter linkage)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Write tools (`create`, `update`) require `manage_options`; read tools require `edit_posts`.
- Conflict checking searches existing `mcp_ai_lf_client` and `mcp_ai_lf_matter` records.
- Practice areas use an 11-value enum (litigation, corporate, real_estate, family, criminal, ip, immigration, bankruptcy, tax, employment, estate_planning).
- Every tool carries the `DISCLAIMER` constant.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/law-firm/intake-management/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../class-wp-mcp-ai-law-firm-calculator.php`](../class-wp-mcp-ai-law-firm-calculator.php) — shared calculator
