# ERP EZUite Toolkit

> EZUite ERP integration tools for product and data retrieval.

## Purpose

Tools for connecting to and querying EZUite ERP systems to retrieve products, inventory, and related business data.

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| EZUite ERP | `ezuite_erp` | General EZUite ERP query and operations |
| EZUite ERP Get Products | `ezuite_erp_get_products` | Retrieve product catalog from EZUite ERP |
| EZuite Inventory | `ezuite_inventory` | Search cached EZuite inventory from local CCT (zero API cost) |
| EZuite Sync | `ezuite_sync` | Trigger sync, check status, or run dry-run validation |
| EZuite Settings | `ezuite_settings` | Read and manage EZuite Toolkit configuration |

## Dependencies

- WordPress 6.0+
- WooCommerce (required for activation)
- JetEngine (optional — needed for CCT storage; toolkit will surface a clear error if missing)
- EZuite ERP API credentials
- NV oOS Pro v1.9.0+

## Architecture

```
EZuite ERP API ──(Action Scheduler)──▶ JetEngine CCT (local cache)
                                           │
                    ┌──────────────────────┤
                    ▼                      ▼
            ezuite_inventory        ezuite_erp_get_products
            (reads CCT)             (reads API directly)
```

Inventory read tools query the CCT cache directly — no API calls per query. Background sync via Action Scheduler keeps data fresh on configurable intervals (5–1440 minutes).

## Key Files

| File | Role |
|------|------|
| `class-wp-mcp-ai-ezuite-cct-manager.php` | JetEngine CCT cache management (read/write/sync) |
| `class-wp-mcp-ai-ezuite-sync-engine.php` | Action Scheduler background sync orchestration |
| `class-wp-mcp-ai-ezuite-migration.php` | Migration from standalone EZuite sync plugin |
| `class-wp-mcp-ai-pro-tool-ezuite-inventory.php` | Inventory search/filter from CCT cache |
| `class-wp-mcp-ai-pro-tool-ezuite-sync.php` | Sync trigger, status, and dry-run operations |
| `class-wp-mcp-ai-pro-tool-ezuite-settings.php` | Toolkit configuration read/write |

## Admin Page

Located at **EZuite Toolkit** (top-level admin menu). Extends `WP_MCP_AI_Toolkit_Settings_Base`.

Tabs:
- **Overview** — Connection status, sync stats, quick sync button
- **Configuration** — API credentials, sync interval, WC sync toggle, CCT slug
- **Tools Management** — Enable/disable individual tools

## See Also

- [Pro Toolkits index](../../../docs/toolkits/README.md)
- [ERP Connector: `addons/pro/includes/class-wp-mcp-ai-erp-ezuite.php`](../../class-wp-mcp-ai-erp-ezuite.php)
- [ERP Interface: `addons/pro/includes/interface-wp-mcp-ai-erp-connector.php`](../../interface-wp-mcp-ai-erp-connector.php)
