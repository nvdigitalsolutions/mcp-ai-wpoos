# Shopify Sync Integration

> Cache-first Shopify↔WooCommerce synchronization for NV oOS AI assistants.

## What is Shopify Sync?

The Shopify Sync Pro Toolkit adds a **local cache layer** between your Shopify stores and WooCommerce, enabling AI assistants to query inventory, products, and analytics with **zero GraphQL API cost**. Instead of hitting the Shopify API on every query (costing 15–300 GraphQL points each), data is synchronized to a JetEngine CCT cache via background Action Scheduler jobs and real-time Shopify webhooks.

**Key difference from the existing Shopify tools:** The standard Shopify tools (`shopify_products`, `shopify_inventory`, etc.) make live API calls for every query — great for real-time operations and mutations but expensive for bulk/analytics queries. The Sync tools read from the local CCT cache — zero cost, sub-50ms latency — ideal for dashboards, bulk searches, and high-frequency AI interactions.

## Prerequisites

| Requirement | Details |
|---|---|
| **WordPress** | 6.0+ |
| **WooCommerce** | Active and configured |
| **NV oOS Pro** | v1.3.0+ |
| **Shopify Store** | Admin API access token (shpat_… or shpca_…) configured in Remote Sites |
| **JetEngine** | Required for CCT cache storage (optional but strongly recommended — tools return clear error if missing) |
| **Action Scheduler** | Bundled with WooCommerce — no separate install needed |

## Installation & Activation

### Step 1: Configure Shopify Connection

1. Go to **NV oOS → Remote Sites** and create a Shopify connection:
   - **Connection Type:** Shopify
   - **API Mode:** Admin API
   - **Shop Domain:** `yourstore.myshopify.com`
   - **Access Token:** Your Admin API access token (from Shopify Admin → Settings → Apps → Develop apps)
2. Generate the token in Shopify with at minimum these scopes:
   - `read_products`, `read_inventory`, `read_locations`, `read_orders`
   - For webhooks: `read_webhook_subscriptions`, `write_webhook_subscriptions`

### Step 2: Enable the Toolkit

1. Go to **NV oOS → Settings**
2. Under **Pro Toolkits**, check **Enable Shopify Sync Toolkit**
3. Save settings

### Step 3: Configure Sync

1. Go to the new **Shopify Sync** menu (position 58 in admin)
2. Under **Configuration**, select which Shopify connections to synchronize
3. Set the sync interval (5/15/30/60 minutes)
4. Choose sync direction:
   - **Shopify → WooCommerce only** (default) — Shopify is the source of truth
   - **Read-Only** — Cache only, no WC stock changes
5. Optionally enable webhooks for real-time updates at zero API cost
6. Save settings

### Step 4: Verify

1. Go to the **Overview** tab
2. Click **Sync Now** on a connection to run the initial sync
3. Check the CCT row count and freshness indicators

## Understanding GraphQL Costs

Shopify's Admin GraphQL API uses a **point-based cost model**:

| Operation | Cost | Notes |
|---|---|---|
| Bulk Operation (all products) | 10 pts | One-time flat cost for full catalog export |
| Single product query | ~15–50 pts | Used by webhook-driven updates |
| List 250 products | ~150–300 pts | Expensive — avoid in loops |
| Webhook delivery | 0 pts | Real-time, zero cost |
| **CCT cache read** | **0 pts** | All Sync tool reads use the cache |

Each store gets a **1,000-point bucket** that refills at 50 pts/second. The Sync toolkit is designed to stay well within this budget:
- Full sync: 10 pts per Bulk Operation (runs every 15–60 min)
- Webhook updates: 0 pts
- All AI tool reads: 0 pts (from CCT cache)

Monitor your daily cost in the **Overview** tab (cost gauge) or via WP-CLI: `wp shopify-sync cost-report`

## Webhook Setup (Recommended)

Webhooks give you **real-time inventory updates at zero API cost**. Shopify pushes changes to your WordPress site the moment they happen.

### Auto-Registration (GraphQL)

In the **Shopify Sync → Configuration** tab:
1. Enable **Webhooks** checkbox
2. Save settings
3. The toolkit registers webhooks automatically via the Admin GraphQL API

The webhook endpoint URL is:
```
https://yoursite.com/wp-json/mcp-ai/v1/shopify/webhook
```

### Manual Setup (via Shopify Admin)

If auto-registration fails, you can set up webhooks manually in Shopify Admin:
1. Go to **Settings → Notifications → Webhooks**
2. Click **Create webhook**
3. Select event: `Product update`, `Product deletion`, `Inventory level update`
4. Format: JSON
5. URL: `https://yoursite.com/wp-json/mcp-ai/v1/shopify/webhook`

### Troubleshooting Webhooks

| Issue | Solution |
|---|---|
| HMAC verification fails | Verify the API token in Remote Sites matches the one in Shopify. The toolkit uses the Admin API access token as the HMAC secret. |
| Webhooks not firing | Check that your site is publicly accessible. Local/dev environments can't receive webhooks — use polling-based sync instead. |
| "Unknown shop" error (404) | The shop domain in the webhook must match the URL of the Remote Sites connection exactly. |
| Webhook timeout (>5s) | The toolkit processes webhooks in <2 seconds. If timeouts persist, check for slow plugins or PHP errors. |

## Using the AI Tools

All Sync tools are available to AI assistants configured with `manage_woocommerce` capability (read) or `manage_options` (write/settings).

### Example Natural Language Prompts

**Inventory queries (zero API cost):**
- "Show me all products below reorder threshold across all Shopify locations"
- "What's the total inventory value for Nike products?"
- "List all out-of-stock items at the warehouse location"
- "Compare stock levels of product SKU ABC-123 across all locations"

**Product browsing (zero API cost):**
- "List all active products from vendor Patagonia"
- "Show me the top 10 products by available quantity"
- "What product types are available in the catalog?"
- "Find product by SKU SHOES-001 and show all its inventory levels"

**Order queries (API cost for full detail):**
- "Show me the 10 most recent orders"
- "Get details for order #1001"
- "What's the total revenue from the last 50 orders?"

**Analytics (zero API cost):**
- "Give me an inventory summary across all locations"
- "Which products are selling fastest? Which are overstocked?"
- "Break down inventory by vendor"

**Settings & Management (manage_options required):**
- "Show me the current sync status"
- "What's today's GraphQL cost so far?"
- "Trigger a full sync now"

## WP-CLI Commands

```bash
# Show sync status for all connections
wp shopify-sync status

# Show status for a specific connection
wp shopify-sync status --connection=conn_abc123

# Trigger a manual full sync
wp shopify-sync trigger conn_abc123

# Clear the CCT cache (requires --force)
wp shopify-sync clear-cache conn_abc123 --force

# Register Shopify webhooks
wp shopify-sync register-webhooks conn_abc123

# Unregister webhooks
wp shopify-sync unregister-webhooks conn_abc123

# View GraphQL cost report
wp shopify-sync cost-report
wp shopify-sync cost-report --connection=conn_abc123 --days=30

# List sync-enabled connections
wp shopify-sync list-connections
```

## Troubleshooting Common Issues

### Sync not running

1. Check **Shopify Sync → Overview** — does the connection card show "Fresh"?
2. Verify Action Scheduler is working: check **Tools → Scheduled Actions** for `wp_mcp_ai_shopify_full_sync_{conn_id}` hooks
3. Check PHP error logs for sync errors
4. Run `wp shopify-sync status` to see last error

### GraphQL cost budget exhausted

1. The sync engine automatically skips syncs when <10% budget remains
2. Wait for the budget to refill (50 pts/sec = ~20 minutes for full refill)
3. Consider increasing the sync interval to reduce cost
4. Enable webhooks — they cost 0 points and reduce the need for frequent polls

### CCT not found / JetEngine missing

1. Install and activate JetEngine from Crocoblock
2. The CCT is auto-created on first sync — no manual setup needed
3. If the CCT slug was changed, make sure it exists in JetEngine → Custom Content Types

### Products not matching WooCommerce

1. The toolkit matches by SKU: Shopify variant SKU must match WooCommerce product SKU exactly
2. Check that SKUs are populated in both systems
3. Case-sensitive matching — "ABC-123" ≠ "abc-123"

### Stale data

1. Check the freshness indicator in the Overview tab
2. Run a manual sync: `wp shopify-sync trigger <conn_id>` or click "Sync Now"
3. Enable webhooks for real-time updates
4. Reduce the sync interval in Configuration

## Comparison: Sync Tools vs Live API Tools

| | Sync Tools | Live API Tools |
|---|---|---|
| **Read cost** | 0 GraphQL points | 15–300 pts per query |
| **Response time** | <50ms (CCT cache) | 200–2000ms (API round trip) |
| **Data freshness** | 1–60 min (+ webhook real-time) | Real-time |
| **Best for** | Bulk queries, analytics, dashboards, frequent reads | Single-item mutations, real-time lookups, ad-hoc operations |
| **Write support** | WC stock writeback (Shopify→WC) | Full CRUD on Shopify |
| **Tool slugs** | `shopify_sync_inventory`, `shopify_sync_products`, etc. | `shopify_products`, `shopify_inventory`, `shopify_orders`, etc. |

## See Also

- [Shopify Sync Proposal](../project/proposals/SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md)
- [Shopify Sync Implementation Plan](../project/proposals/SHOPIFY-SYNC-IMPLEMENTATION-PLAN.md)
- [Shopify Sync Toolkit README](../../addons/pro/includes/tools/shopify-sync/README.md)
- [Shopify Admin GraphQL API Documentation](https://shopify.dev/docs/api/admin-graphql)
- [Shopify Webhooks Documentation](https://shopify.dev/docs/apps/build/webhooks)
