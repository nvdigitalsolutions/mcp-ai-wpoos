# CRM

## Purpose

Houses 11 CRM tools: contact management (CRUD + list/search), company creation/listing, interaction capture (MemPalace-backed), email search across accounting/correspondence/leads, company research, Upwork proposal drafting, Upwork job scoring, and Upwork job search.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry |
| **Optional dependencies** | CRM toolkit setting; `WP_MCP_AI_Toolkit_Data_Store_Factory` (CCT/CPT storage backend) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Manage_CRM_Contact` | `class-wp-mcp-ai-tool-manage-crm-contact.php` | tool registry |
| `WP_MCP_AI_Tool_Create_Company` | `class-wp-mcp-ai-tool-create-company.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Capture_Interaction` | `class-wp-mcp-ai-tool-crm-capture-interaction.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Email_Search_Accounting` | `class-wp-mcp-ai-tool-crm-email-search-accounting.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Email_Search_Correspondence` | `class-wp-mcp-ai-tool-crm-email-search-correspondence.php` | tool registry |
| `WP_MCP_AI_Tool_CRM_Email_Search_Leads` | `class-wp-mcp-ai-tool-crm-email-search-leads.php` | tool registry |
| `WP_MCP_AI_Tool_Draft_Upwork_Proposal` | `class-wp-mcp-ai-tool-draft-upwork-proposal.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Companies` | `class-wp-mcp-ai-tool-get-companies.php` | tool registry |
| `WP_MCP_AI_Tool_Research_Company` | `class-wp-mcp-ai-tool-research-company.php` | tool registry |
| `WP_MCP_AI_Tool_Score_Upwork_Job` | `class-wp-mcp-ai-tool-score-upwork-job.php` | tool registry |
| `WP_MCP_AI_Tool_Search_Upwork_Jobs` | `class-wp-mcp-ai-tool-search-upwork-jobs.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (CRM toggle), CCT/CPT data store for contacts and companies
- **Writes to:** Contact and company records via `WP_MCP_AI_Toolkit_Data_Store`; MemPalace via `WP_MCP_AI_Pro_Capture_Tool_Base`
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Toolkit_Data_Store_Factory`, `WP_MCP_AI_Validator_Service` (email/phone validation), `WP_MCP_AI_Memory_Capture_Service`
- **Events fired:** None
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Contact management uses the toolkit data store pattern (CCT via JetEngine, CPT fallback).
- Email search tools provide targeted accounting, correspondence, and lead-focused searches.
- Upwork tools (proposal drafting, job scoring, job search) are grouped under CRM.
- `crm_capture_interaction` extends `WP_MCP_AI_Pro_Capture_Tool_Base` with account/ wing prefix.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/crm/
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Pro tools index
