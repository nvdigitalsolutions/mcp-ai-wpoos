# Customers

## Purpose

Customer CRUD tools for the CRM toolkit — create, retrieve, update, delete, and list customer records backed by the `mcp_ai_customer` custom post type.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/tools/crm/init.php` |
| **Optional dependencies** | `WP_MCP_AI_Customer_CPT` (registered in `addons/pro/includes/class-wp-mcp-ai-customer-cpt.php`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Create_Customer` | `class-wp-mcp-ai-tool-create-customer.php` | tool registry, assistant presets |
| `WP_MCP_AI_Tool_Get_Customer` | `class-wp-mcp-ai-tool-get-customer.php` | tool registry, assistant presets |
| `WP_MCP_AI_Tool_Update_Customer` | `class-wp-mcp-ai-tool-update-customer.php` | tool registry, assistant presets |
| `WP_MCP_AI_Tool_Delete_Customer` | `class-wp-mcp-ai-tool-delete-customer.php` | tool registry, assistant presets |
| `WP_MCP_AI_Tool_List_Customers` | `class-wp-mcp-ai-tool-list-customers.php` | tool registry, assistant presets |

## Inputs / Outputs / Neighbors

- **Reads from:** `mcp_ai_customer` CPT post meta (contact details, lifecycle stage, lead source, assigned owner), `wp_mcp_ai_crm_toolkit_settings` option
- **Writes to:** `mcp_ai_customer` CPT + post meta, CRM audit ledger via `WP_MCP_AI_CRM_Audit`
- **Upstream callers:** Pro tool registry, CRM MCP server, assistant tool presets, Customer Research page, customer conversion workflow (`convert_lead_to_customer`)
- **Downstream collaborators:** `WP_MCP_AI_Customer_CPT` (post type + meta registration), `WP_MCP_AI_CRM_Engine` (settings, scoring, lifecycle), `WP_MCP_AI_CRM_Audit` (PII access logging)
- **Events fired:** `wp_mcp_ai_crm_after_audit` (on every PII read)
- **Events listened to:** None directly — invoked through tool registry

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- All tools implement `is_available()` / `get_unavailable_reason()` — checked by orchestrator before `execute()`.
- Customer data is stored as post meta on the `mcp_ai_customer` CPT, not as a CCT — designed for multisite portability and standard WP_Query filtering.
- Lifecycle transitions (`lead` → `customer`) are handled by `convert_lead_to_customer`, which delegates to `create_customer`.
- PII read/write is audited through `WP_MCP_AI_CRM_Audit`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/crm/customers/
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
- Siblings: [`../leads/`](../leads/), [`../deals/`](../deals/), [`../support/`](../support/)
- Related CPT: [`../../class-wp-mcp-ai-customer-cpt.php`](../../class-wp-mcp-ai-customer-cpt.php)
