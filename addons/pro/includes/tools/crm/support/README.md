# Support — Ticket Management

## Purpose

Support ticket lifecycle management for the CRM toolkit — create, retrieve, update, list, classify, escalate, resolve, reopen, and merge tickets backed by the `mcp_ai_support_ticket` custom post type, with SLA enforcement, automated notifications, and Zendesk sync support.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/tools/crm/support/init.php` |
| **Optional dependencies** | `WP_MCP_AI_Support_Ticket_CPT` (registered in `addons/pro/includes/class-wp-mcp-ai-support-ticket-cpt.php`), Zendesk API credentials (for sync) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Create_Support_Ticket` | `class-wp-mcp-ai-tool-create-support-ticket.php` | tool registry, assistant presets |
| `WP_MCP_AI_Tool_Get_Support_Ticket` | `class-wp-mcp-ai-tool-get-support-ticket.php` | tool registry |
| `WP_MCP_AI_Tool_Update_Support_Ticket` | `class-wp-mcp-ai-tool-update-support-ticket.php` | tool registry |
| `WP_MCP_AI_Tool_List_Support_Tickets` | `class-wp-mcp-ai-tool-list-support-tickets.php` | tool registry |
| `WP_MCP_AI_Tool_Classify_Support_Ticket` | `class-wp-mcp-ai-tool-classify-support-ticket.php` | tool registry, automation engine |
| `WP_MCP_AI_Tool_Escalate_Support_Ticket` | `class-wp-mcp-ai-tool-escalate-support-ticket.php` | tool registry, SLA enforcement |
| `WP_MCP_AI_Tool_Resolve_Support_Ticket` | `class-wp-mcp-ai-tool-resolve-support-ticket.php` | tool registry |
| `WP_MCP_AI_Tool_Reopen_Support_Ticket` | `class-wp-mcp-ai-tool-reopen-support-ticket.php` | tool registry |
| `WP_MCP_AI_Tool_Merge_Support_Tickets` | `class-wp-mcp-ai-tool-merge-support-tickets.php` | tool registry |
| `WP_MCP_AI_Tool_Get_Ticket_SLA_Report` | `class-wp-mcp-ai-tool-get-ticket-sla-report.php` | tool registry, admin dashboard |
| `WP_MCP_AI_CRM_Ticket_Automation` | `class-wp-mcp-ai-crm-ticket-automation.php` | auto-classify on creation, SLA breach detection |
| `WP_MCP_AI_CRM_Ticket_Notifications` | `class-wp-mcp-ai-crm-ticket-notifications.php` | assignee/creator email notifications on status change |

## Inputs / Outputs / Neighbors

- **Reads from:** `mcp_ai_support_ticket` CPT post meta (status, priority, assignee, SLA deadline, classification), `wp_mcp_ai_crm_toolkit_settings` option (SLA thresholds, notification templates)
- **Writes to:** `mcp_ai_support_ticket` CPT + post meta, CRM audit ledger, Zendesk API (optional sync)
- **Upstream callers:** Pro tool registry, CRM MCP server, assistant tool presets, Support Ticket admin settings page
- **Downstream collaborators:** `WP_MCP_AI_Support_Ticket_CPT` (post type + meta), `WP_MCP_AI_CRM_Engine` (settings, routing), `WP_MCP_AI_CRM_Audit` (PII logging), `WP_MCP_AI_CRM_Ticket_Automation` (auto-classify + SLA cron), `WP_MCP_AI_CRM_Ticket_Notifications` (email dispatch)
- **Events fired:** `wp_mcp_ai_crm_ticket_created`, `wp_mcp_ai_crm_ticket_escalated`, `wp_mcp_ai_crm_ticket_resolved`, `wp_mcp_ai_crm_sla_breach`
- **Events listened to:** `wp_mcp_ai_crm_ticket_check_sla` (cron action for SLA enforcement)

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- All tools implement `is_available()` / `get_unavailable_reason()`.
- SLA deadlines are calculated from ticket creation time using configurable thresholds (default: P1=4h, P2=8h, P3=24h, P4=72h).
- `class-wp-mcp-ai-crm-ticket-automation.php` runs on `wp_mcp_ai_crm_ticket_check_sla` cron action — detects breached SLAs and triggers escalation.
- Zendesk sync is opt-in — enabled via `wp_mcp_ai_crm_toolkit_settings['integrations']['zendesk_enabled']` with API credentials stored in Password Vault.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/crm/support/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent CRM toolkit index
- [`../../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](../../../../docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md) — full enhancement roadmap

## See Also

- Parent: [`../`](../) — CRM toolkit root
- Siblings: [`../customers/`](../customers/), [`../leads/`](../leads/)
- Related CPT: [`../../class-wp-mcp-ai-support-ticket-cpt.php`](../../class-wp-mcp-ai-support-ticket-cpt.php)
- Admin page: [`../../admin/class-wp-mcp-ai-support-ticket-settings-page.php`](../../admin/class-wp-mcp-ai-support-ticket-settings-page.php)
