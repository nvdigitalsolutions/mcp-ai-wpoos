# FlowHub Toolkit

> FlowHub POS Inventory Sync toolkit for cannabis dispensary inventory management.

## Purpose

Tools for synchronizing FlowHub dispensary inventory with WooCommerce via a JetEngine CCT cache layer. Enables AI assistants to query inventory, products, and locations instantly from local data, with background sync keeping the cache fresh.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| FlowHub Inventory | `flowhub_inventory` | Search, filter, and retrieve inventory levels |
| FlowHub Products | `flowhub_products` | Browse product catalog and list categories |
| FlowHub Locations | `flowhub_locations` | List dispensary locations with stock counts |
| FlowHub Sync | `flowhub_sync` | Trigger sync operations and check status |
| FlowHub Settings | `flowhub_settings` | Manage toolkit configuration and test connection |

## Architecture

```
FlowHub API ──(Action Scheduler)──▶ JetEngine CCT (local cache)
                                           │
                    ┌──────────────────────┼──────────────────────┐
                    ▼                      ▼                      ▼
            flowhub_inventory      flowhub_products       flowhub_locations
            (reads CCT)            (reads CCT)            (reads CCT)
```

All read tools query the CCT cache directly — no API calls per query. Background sync via Action Scheduler keeps data fresh on configurable intervals (1–60 minutes).

## Dependencies

- WordPress 6.0+
- WooCommerce (required for activation)
- JetEngine (optional — needed for CCT storage; toolkit will surface a clear error if missing)
- FlowHub API credentials (client ID + API key)
- NV oOS Pro v1.2.0+

## Registration

Tools are registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`, gated on:
- `wp_mcp_ai_settings['enable_flowhub_toolkit']` toggle
- WooCommerce active
- Not base version

## Key Files

| File | Role |
|------|------|
| `class-wp-mcp-ai-flowhub-client.php` | FlowHub REST API client (HTTP, auth, rate limiting) |
| `class-wp-mcp-ai-flowhub-cct-manager.php` | JetEngine CCT cache management (read/write/sync) |
| `class-wp-mcp-ai-flowhub-sync-engine.php` | Action Scheduler background sync orchestration |
| `class-wp-mcp-ai-flowhub-migration.php` | Migration from standalone flowhub-inventory-sync plugin |
| `trait-wp-mcp-ai-flowhub-connection-resolver.php` | Shared connection/dependency checks for tools |

## Admin Page

Located at **FlowHub Toolkit** (top-level admin menu, position 57). Extends `WP_MCP_AI_Toolkit_Settings_Base`.

Tabs:
- **Overview** — Connection status, sync stats, quick sync button
- **Configuration** — API credentials, sync interval, WC sync toggle, CCT slug
- **Tools Management** — Enable/disable individual tools

## See Also

- [FlowHub Integration Proposal](../../../../docs/project/proposals/FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md)
- [FlowHub Implementation Plan](../../../../docs/project/proposals/FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md)
- [FlowHub API Documentation](https://www.flowhub.com/)
- [JetEngine CCT Documentation](https://crocoblock.com/knowledge-base/features/custom-content-type/)
