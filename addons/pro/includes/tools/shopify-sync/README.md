# Shopify Sync Toolkit

> Shopify↔WooCommerce cache-first sync toolkit for AI-powered inventory management.

## Purpose

Tools for synchronizing Shopify store inventory, products, and orders with WooCommerce via a JetEngine CCT cache layer. Enables AI assistants to query inventory, products, orders, and analytics instantly from local data — with zero GraphQL API cost per query. Background sync via Action Scheduler + Shopify webhooks keeps the cache fresh on configurable intervals (5–60 minutes).

## Tool Inventory

| Tool | Slug | Description |
|------|------|-------------|
| Shopify Sync Inventory | `shopify_sync_inventory` | Search, filter, and retrieve inventory levels across locations from CCT cache |
| Shopify Sync Products | `shopify_sync_products` | Browse product catalog, search by SKU, list by type/vendor from CCT cache |
| Shopify Sync Orders | `shopify_sync_orders` | List recent orders, get order analytics from CCT cache (headers only; full detail hits API) |
| Shopify Sync Settings | `shopify_sync_settings` | Manage toolkit configuration, test connections, view GraphQL cost reports |
| Shopify Sync Analytics | `shopify_sync_analytics` | Inventory summaries, stock velocity, product performance — all from CCT aggregates |

## Architecture

```
Shopify API ──(Bulk Ops + Webhooks)──▶ JetEngine CCT (shopify_inventory_sync)
                                              │
                    ┌─────────────────────────┼─────────────────────────┐
                    ▼                         ▼                         ▼
            shopify_sync             shopify_sync              shopify_sync
            _inventory               _products                 _analytics
            (reads CCT)              (reads CCT)               (CCT aggregates)
                   │
                   └── All reads: zero GraphQL cost, < 50ms latency
```

All read tools query the CCT cache directly — **no API calls per query**. Background sync via Action Scheduler (full sync using Shopify Bulk Operations at 10 GraphQL points flat) and webhooks (zero-cost real-time deltas) keeps data fresh.

## Key Design: Cache-First, Not Replace

The Sync tools do **NOT** replace the existing Shopify live-API tools (`shopify_products`, `shopify_inventory`, `shopify_orders`, `shopify_customers`, `shopify_catalog`). They complement them:

| Aspect | Live-API Tools | Sync Tools |
|---|---|---|
| **Read path** | Shopify Admin GraphQL (costs points) | CCT cache (zero cost, < 50ms) |
| **Best for** | Ad-hoc operations, mutations, real-time queries | Bulk queries, analytics, dashboards, high-frequency reads |
| **Data freshness** | Real-time | Configurable (1–60 min + webhooks) |

## Dependencies

- WordPress 6.0+
- WooCommerce (required for activation)
- NV oOS Pro v1.3.0+
- `WP_MCP_AI_Shopify_Client` (existing — consumed as-is)
- Remote Sites manager (Shopify connection must be configured)
- JetEngine (optional — needed for CCT storage; toolkit surfaces clear error if missing)
- Action Scheduler (bundled with WooCommerce)

## Registration

Tools are registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`, gated on:
- `wp_mcp_ai_settings['enable_shopify_sync_toolkit']` toggle
- WooCommerce active
- `WP_MCP_AI_Shopify_Client` class exists
- Not base version
- At least one Shopify connection enabled for sync

## Key Files

| File | Role |
|------|------|
| `class-wp-mcp-ai-shopify-sync-cct-manager.php` | JetEngine CCT cache management (read/write/sync) |
| `class-wp-mcp-ai-shopify-sync-engine.php` | Action Scheduler background sync + GraphQL cost tracking |
| `class-wp-mcp-ai-shopify-sync-webhook-handler.php` | REST endpoint + HMAC verification for real-time Shopify webhooks |
| `trait-wp-mcp-ai-shopify-sync-connection-resolver.php` | Shared connection/dependency checks for all sync tools |

## Admin Page

Located at **Shopify Sync** (top-level admin menu, position 58). Extends `WP_MCP_AI_Toolkit_Settings_Base`.

Tabs:
- **Overview** — Per-connection status cards (sync freshness, CCT row count, GraphQL cost gauge, webhook status)
- **Configuration** — Sync connections selector, interval, direction, WC sync toggle, CCT slug
- **Sync Log** — Last 50 sync events, filterable, exportable
- **Webhooks** — Register/unregister, HMAC test, delivery URL

## WP-CLI

```bash
wp shopify-sync status [--connection=<id>]
wp shopify-sync trigger <connection_id>
wp shopify-sync clear-cache <connection_id> [--force]
wp shopify-sync register-webhooks <connection_id>
wp shopify-sync unregister-webhooks <connection_id>
wp shopify-sync cost-report [--connection=<id>] [--days=7]
wp shopify-sync list-connections
```

## See Also

- [Shopify Sync Proposal](../../../../docs/project/proposals/SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md)
- [Shopify Sync Implementation Plan](../../../../docs/project/proposals/SHOPIFY-SYNC-IMPLEMENTATION-PLAN.md)
- [FlowHub Toolkit](../flowhub/README.md) — Reference architecture
- [Shopify Client](../../includes/class-wp-mcp-ai-shopify-client.php) — Existing GraphQL client (consumed unchanged)
- [Shopify Admin GraphQL API](https://shopify.dev/docs/api/admin-graphql)
