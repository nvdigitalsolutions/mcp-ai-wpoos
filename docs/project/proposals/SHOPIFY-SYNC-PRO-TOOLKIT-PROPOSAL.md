# Shopify Sync — Pro Toolkit Integration Proposal

**Date:** June 28, 2026  
**Status:** 📋 PROPOSAL  
**Plugin Target:** NV oOS Pro v1.3.0+  
**Depends On:** Existing `WP_MCP_AI_Shopify_Client` (Admin GraphQL API + Catalog API), Remote Sites connections  
**Related:** [FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md](./FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md) — reference architecture  

---

## Executive Summary

Proposal to build a **Shopify Sync Pro Toolkit** that adds background inventory/product synchronization between Shopify stores and WooCommerce, backed by a JetEngine CCT-based local cache and Action Scheduler. Unlike the existing 5 Shopify tools (`shopify_products`, `shopify_inventory`, `shopify_orders`, `shopify_customers`, `shopify_catalog`) which make **live API calls on every query**, this toolkit adds a **cache-first sync layer** so AI assistants query locally cached data with sub-50ms latency and zero API cost. The existing live-API tools remain available for real-time operations; the sync toolkit adds the persistence, scheduling, and bidirectional writeback layer.

**Key Recommendation:** Proceed as a Pro Toolkit with CCT cache layer and Action Scheduler background sync.

**Value Proposition:** Merchants running WooCommerce + Shopify gain sub-second AI inventory queries, automated cross-platform stock synchronization, cost-free reads (no GraphQL query cost per AI interaction), and natural-language commerce analytics — while NV oOS gains a reusable external-API sync pattern that can be replicated for any platform with a REST or GraphQL API.

---

## 1. Research Summary — Industry Standards & Best Practices

### 1.1 The Shopify ↔ WooCommerce Sync Landscape

Analysis of leading Shopify-WooCommerce connectors (Shopify Connector for WooCommerce, QuickSync, W2S Sync, WebToffee, miniOrange Syncito) reveals consistent architectural patterns:

| Reality | Architectural Implication |
|---|---|
| **GraphQL query cost model** (50–1,000 points per query, 1,000-point bucket refills at 50 pts/sec) | Cache-first is not optional — it's a cost requirement. Every AI query that hits the GraphQL API burns points. |
| **Bulk Operations API** (flat 10-point cost, JSONL output) | Use bulk operations for initial/full syncs to export entire catalogs cheaply. |
| **Webhooks for real-time deltas** (product/update, inventory_levels/update) | Webhooks eliminate polling for active stores. Register once, receive push events. |
| **Multi-location inventory** (each variant has per-location quantities) | Cache must shard by `(variant_id, location_id)` compound key. |
| **API versioning** (quarterly releases, 12-month support window) | Pin API version in connection settings; schedule upgrades. |
| **Rate limiting** (bucket-based, 50 pts/sec refill, 2 req/sec REST) | Sync engine must throttle pages, respect `Retry-After` headers, use exponential backoff. |
| **HPOS compatibility** (WooCommerce High-Performance Order Storage) | Use `wc_update_product_stock()`, not `update_post_meta('_stock', ...)`. |

### 1.2 Cache-First Architecture Pattern (Industry Standard)

All major commerce sync platforms (CedCommerce, WebToffee, QuickSync, miniOrange) follow the same five-layer pattern:

```
External API ──(scheduled/bulk pull)──▶ Local Sync Table (CCT or custom)
                                              │
                    ┌─────────────────────────┼─────────────────────────┐
                    ▼                         ▼                         ▼
              WooCommerce              Admin UI                    AI Tools
         (wc_update_stock)        (status, logs,              (read cache,
                                 manual trigger)           zero API cost)
```

**Key industry patterns:**

1. **Bulk-first initial sync** — Use the cheapest API path (Shopify Bulk Operations, flat 10 pts per operation regardless of dataset size) for the initial catalog pull. Delta syncs then use incremental GraphQL queries filtered by `updated_at`.

2. **Idempotent upserts** — Every sync operation is safe to run repeatedly. Records are matched on `(variant_id, location_id)` compound key. Changed records are updated; unchanged records (matched by `sync_hash`) are skipped.

3. **Non-destructive writes** — Shopify→WC sync updates only `stock_quantity` and `price` fields. It never overwrites WooCommerce product descriptions, images, categories, or SEO metadata that a store manager has manually curated.

4. **Directional configuration** — Three modes: Shopify→WooCommerce (one-way, default), Bidirectional (with last-write-wins conflict resolution), and Read-Only (cache only, no WC writes).

5. **Error isolation** — A failed Shopify API call does not break the WooCommerce storefront or the AI tool responses. Stale cached data is always better than no data. Sync errors are logged, emailed to admin, and surfaced as dismissible admin notices.

6. **GraphQL cost telemetry** — The existing `WP_MCP_AI_Shopify_Client` already logs cost metadata. The sync engine extends this to track cumulative daily cost and warn when approaching the 1,000-point bucket exhaustion.

### 1.3 JetEngine CCT as Local Cache — Rationale

Following the proven FlowHub architecture (PR #5501), the CCT cache decision matrix is:

| Criterion | JetEngine CCT | Custom Table (`wp_shopify_sync`) | WordPress Transients |
|---|---|---|---|
| **Query performance** | ✅ Indexed, WP_Query-compatible | ✅ Indexed | ⚠️ Serialized blob, no indexing |
| **Admin UI (list table)** | ✅ JetEngine provides free admin list | ❌ Must build custom | ❌ Not applicable |
| **REST API** | ✅ Auto-exposed by JetEngine | ❌ Must build custom | ❌ Not applicable |
| **Field evolution** | ✅ `jet_engine()->cct->add_field()` | ⚠️ Manual `dbDelta` on update | ❌ Not applicable |
| **Relation support** | ✅ JetEngine Relations (CCT↔WC Product) | ❌ Manual JOIN queries | ❌ Not applicable |
| **Multi-site** | ✅ Per-site tables (JetEngine native) | ⚠️ Must handle `$wpdb->prefix` | ⚠️ Shared if network-activated |
| **Data volume** | ✅ Handles 10K–100K rows | ✅ Handles 10K–100K rows | ❌ Degrades above ~100 rows |
| **Cost to build** | ✅ Zero — JetEngine handles UI, REST, CRUD | ❌ Weeks of custom admin UI | ✅ Quick but limits scalability |

**Decision:** Use JetEngine CCT with slug `shopify_inventory_sync` (configurable). This provides a free admin list table, REST API endpoints, and JetEngine Relations for linking cached rows to WooCommerce products — all without writing any UI code.

### 1.4 Action Scheduler vs. WP-Cron vs. Webhooks

| Criterion | Action Scheduler | WP-Cron | Shopify Webhooks |
|---|---|---|---|
| **Concurrency guard** | ✅ `unique` parameter | ❌ No built-in lock | ✅ Event-driven (no polling) |
| **Retry on failure** | ✅ Configurable retry + backoff | ❌ Silent failure | ⚠️ Shopify retries 19 times over 48h |
| **Admin visibility** | ✅ Built-in table + WP-CLI | ❌ Requires WP Crontrol | ❌ Requires external monitoring |
| **Bundled with WC** | ✅ Zero dependency | ✅ Core WordPress | ❌ Requires public endpoint |
| **Real-time capability** | ⚠️ Minimum 1-minute interval | ⚠️ Pseudo-cron (page-load dependent) | ✅ Near-instant |
| **Setup complexity** | ✅ One function call | ✅ One function call | ❌ Webhook registration, HMAC verification, public HTTPS endpoint |

**Decision:** **Two-tier sync strategy:**
1. **Active stores (sync ≤ 5 min):** Register Shopify webhooks for `products/update`, `products/delete`, `inventory_levels/update`. Process webhooks immediately → update CCT cache. Webhook payloads are small (GID + delta) and cost zero GraphQL points.
2. **All stores (configurable 5–60 min):** Action Scheduler recurring job performs a delta-sync using bulk operations (10 pts for full catalog) or incremental GraphQL queries filtered by `updated_at`. Acts as a safety net for missed/dropped webhooks.
3. **On-demand:** AI tools can trigger `sync_now` for immediate full refresh (burns query points; requires `manage_options`).

### 1.5 Shopify GraphQL Cost Economics

Understanding the cost model is essential to the architecture:

| Operation | GraphQL Cost | Sync Strategy |
|---|---|---|
| **Bulk Operation** (export all products) | **10 points** (flat) | Use for initial + periodic full syncs |
| **Single product query** (with variants, images, inventory) | ~15–50 points | Avoid in sync loops |
| **List products** (250 items, with variants) | ~150–300 points | Use for delta sync (filtered by `updated_at`) |
| **Inventory levels per location** (250 items) | ~50–100 points | Use for location-specific delta sync |
| **Webhook delivery** | **0 points** | Preferred for real-time updates |

**Daily Budget Strategy (1,000-point bucket):**
- Bulk operation (all products): 10 pts → run once every 6 hours = 40 pts/day
- Bulk operation (all orders): 10 pts → run once every 6 hours = 40 pts/day
- Delta inventory sync (per location): ~100 pts → run every 15 minutes × 4 locations = 1,600 pts/day … **exceeds budget!**

**Solution for multi-location stores:** Use webhooks for inventory deltas (0 pts) and bulk operations for periodic full reconciliation (10 pts). Only fall back to GraphQL polling when webhooks are unavailable. The sync engine dynamically selects the cheapest path based on connection configuration.

---

## 2. Proposed Architecture

### 2.1 Component Diagram

```
┌──────────────────────────────────────────────────────────────┐
│  ADMIN UI                                                     │
│  Shopify Sync Toolkit Settings Page                           │
│  (extends WP_MCP_AI_Toolkit_Settings_Base)                    │
│  Tabs: Overview · Configuration · Sync Log · Webhook Status   │
├──────────────────────────────────────────────────────────────┤
│  TOOLS (LLM-facing, cache-first reads)                        │
│  shopify_sync_inventory  │ shopify_sync_products              │
│  shopify_sync_orders     │ shopify_sync_settings              │
│  shopify_sync_analytics                                       │
│  (read CCT cache; write via sync engine; canonical envelope)   │
├──────────────────────────────────────────────────────────────┤
│  SYNC ENGINE                                                  │
│  WP_MCP_AI_Shopify_Sync_Engine                                │
│  → Action Scheduler: wp_mcp_ai_shopify_full_sync              │
│  → Action Scheduler: wp_mcp_ai_shopify_wc_sync                │
│  → REST Endpoint: /wp-json/mcp-ai/v1/shopify/webhook (HMAC)   │
├──────────────────────┬───────────────────────────────────────┤
│  EXISTING CLIENT     │  CCT MANAGER                           │
│  (reused — NO CHANGES)│  (local cache read/write)              │
│  WP_MCP_AI_Shopify_  │  → JetEngine CCT: shopify_sync_cache  │
│  Client              │  → Auto-create columns                 │
│  → Admin GraphQL     │  → Bulk upsert from JSONL              │
│  → Bulk Operations   │  → Sync hash change detection          │
│  → Catalog API       │  → Distinct value indices              │
└──────┬───────────────┴──────────────┬────────────────────────┘
       │                              │
       ▼                              ▼
┌──────────────┐        ┌─────────────────────────┐
│ Shopify API  │        │ WooCommerce              │
│ (external)   │        │ wc_update_product_stock()│
│ → GraphQL    │        │ wc_get_product_id_by_sku│
│ → Bulk Ops   │        │ HPOS-compatible          │
│ → Webhooks   │        └─────────────────────────┘
└──────────────┘
```

### 2.2 Data Flow (Read Path — AI Tool Query)

```
LLM Agent: "show me all Shopify products below reorder threshold"
        │
        ▼
shopify_sync_inventory tool (execute)
        │
        ├─ Check capability: manage_woocommerce
        ├─ Sanitize arguments (Gate 1)
        ├─ CCT_Manager::get_cached_items( filters )
        │   └─ JetEngine CCT query (WP_Query-backed, indexed)
        │       ← Zero API cost, < 50ms latency
        ├─ Escape output (Gate 2)
        └─ Return canonical envelope {success, message, data}
```

### 2.3 Data Flow (Full Sync Path — Bulk Operation)

```
Action Scheduler fires wp_mcp_ai_shopify_full_sync
        │
        ▼
Sync_Engine::run_full_sync()
        │
        ├─ Shopify_Client::bulk_query( products export )
        │   └─ Cost: 10 GraphQL points (flat)
        │   └─ Waits for completion (up to 5 minutes)
        │   └─ Downloads JSONL result
        │
        ├─ For each JSONL line (product):
        │   ├─ Parse product + variants + inventory
        │   ├─ Map Shopify fields → CCT columns
        │   ├─ Compute sync_hash = md5(json_encode(row))
        │   ├─ Compare with existing hash → skip if unchanged
        │   └─ Upsert into CCT (match on variant_id + location_id)
        │
        ├─ (If WC sync enabled) For each CCT row:
        │   ├─ Match to WooCommerce product by SKU
        │   ├─ Update stock_quantity via wc_update_product_stock()
        │   └─ Store linked woo_product_id in CCT row
        │
        ├─ Update option: wp_mcp_ai_shopify_last_sync → now()
        ├─ Log sync event + GraphQL cost telemetry
        └─ Fire hook: wp_mcp_ai_shopify_after_sync
```

### 2.4 Data Flow (Webhook Path — Real-Time Delta)

```
Shopify sends POST to /wp-json/mcp-ai/v1/shopify/webhook
  Headers: X-Shopify-Hmac-SHA256, X-Shopify-Topic, X-Shopify-Shop-Domain
        │
        ▼
Webhook_Handler::process()
        │
        ├─ HMAC verification (sha256(body, client_secret))
        ├─ Topic routing:
        │   ├─ products/update → delta_sync_product(gid)
        │   ├─ products/delete → mark_deleted_in_cct(gid)
        │   └─ inventory_levels/update → delta_sync_inventory(variant_id, location_id)
        │
        ├─ Shopify_Client::get_product(gid)  [single query, ~15 pts]
        │   └─ Upsert single row into CCT
        │
        ├─ (If WC sync enabled) Update WC stock via wc_update_product_stock()
        └─ Return HTTP 200 to Shopify (within 5-second timeout)
```

### 2.5 CCT Schema

**CCT Slug:** `shopify_inventory_sync` (overridable in settings)  
**Primary Key Strategy:** Compound uniqueness on `(variant_id, location_id)` enforced at application layer.

| Column | Type | Source | Notes |
|---|---|---|---|
| `shopify_product_id` | TEXT | `product.id` (GID) | Shopify Product GID |
| `shopify_variant_id` | TEXT | `variant.id` (GID) | Shopify Variant GID (primary match key) |
| `inventory_item_id` | TEXT | `variant.inventoryItem.id` | For inventory adjustments |
| `sku` | TEXT | `variant.sku` | Primary WooCommerce match key |
| `product_title` | TEXT | `product.title` | |
| `variant_title` | TEXT | `variant.title` | |
| `product_type` | TEXT | `product.productType` | |
| `vendor` | TEXT | `product.vendor` | Brand/manufacturer |
| `tags` | TEXT | `product.tags[]` (imploded) | Comma-separated |
| `status` | TEXT | `product.status` | ACTIVE / DRAFT / ARCHIVED |
| `location_id` | TEXT | `location.id` (GID) | Shopify Location GID |
| `location_name` | TEXT | `location.name` | |
| `available_qty` | INT | `inventoryLevel.quantities[name=available]` | |
| `on_hand_qty` | INT | `inventoryLevel.quantities[name=on_hand]` | |
| `incoming_qty` | INT | `inventoryLevel.quantities[name=incoming]` | |
| `reserved_qty` | INT | `inventoryLevel.quantities[name=reserved]` | |
| `price` | DECIMAL(10,2) | `variant.price` | |
| `compare_at_price` | DECIMAL(10,2) | `variant.compareAtPrice` | |
| `image_url` | TEXT | `product.images[0].url` | Primary product image |
| `handle` | TEXT | `product.handle` | Shopify URL handle |
| `woo_product_id` | INT | Set by WC sync | Links to `wp_posts.ID` |
| `woo_variation_id` | INT | Set by WC sync | Links to variation post |
| `shopify_updated_at` | DATETIME | `product.updatedAt` | Source-of-truth timestamp |
| `last_synced_at` | DATETIME | `current_time('mysql')` | When this row was last synced |
| `sync_hash` | TEXT | `md5(json_encode(row))` | Change detection |
| `sync_status` | TEXT | Set by sync engine | synced / pending / error / stale / deleted |
| `raw_data` | LONGTEXT | Full JSON payload | Complete Shopify response for future extraction |

**Column Auto-Creation:** Before each sync, `CCT_Manager::ensure_columns()` checks the JetEngine CCT schema and auto-creates any missing columns via `jet_engine()->cct->add_field()`.

### 2.6 Tool Definitions

All tools implement `WP_MCP_AI_Tool_Interface` + `WP_MCP_AI_Tool_Capability_Flags_Interface` and use a new `WP_MCP_AI_Shopify_Sync_Connection_Resolver` trait (which extends the existing `WP_MCP_AI_Shopify_Connection_Resolver` pattern).

| Tool Slug | Class | Actions | CCT-First? | Capability |
|---|---|---|---|---|
| `shopify_sync_inventory` | `WP_MCP_AI_Pro_Tool_Shopify_Sync_Inventory` | `search`, `get_item`, `get_levels`, `list_locations`, `list_low_stock` | ✅ Yes (read from CCT) | `manage_woocommerce` |
| `shopify_sync_products` | `WP_MCP_AI_Pro_Tool_Shopify_Sync_Products` | `search`, `get_product`, `get_by_sku`, `list_by_type`, `list_by_vendor` | ✅ Yes (read from CCT) | `manage_woocommerce` |
| `shopify_sync_orders` | `WP_MCP_AI_Pro_Tool_Shopify_Sync_Orders` | `search`, `get_order`, `list_recent`, `get_order_analytics` | ✅ CCT for indexes, API for detail | `manage_woocommerce` |
| `shopify_sync_settings` | `WP_MCP_AI_Pro_Tool_Shopify_Sync_Settings` | `get_settings`, `update_settings`, `test_connection`, `get_sync_status`, `get_cost_report` | N/A (admin) | `manage_options` |
| `shopify_sync_analytics` | `WP_MCP_AI_Pro_Tool_Shopify_Sync_Analytics` | `inventory_summary`, `stock_velocity`, `product_performance`, `cross_store_comparison` | ✅ Yes (CCT aggregates) | `manage_woocommerce` |

**Capability Flags for All Tools:**
```php
array(
    'pro',                  // Pro tier
    'external-api',         // Talks to Shopify (sync operations only)
    'requires-credentials', // Needs Shopify connection via Remote Sites
    'requires-capability',  // WP capability check
    'cache-first',          // Reads from CCT, not API
)
```

**Distinction from Existing Shopify Tools:**

| Aspect | Existing Tools (shopify_products, etc.) | Sync Tools (shopify_sync_*) |
|---|---|---|
| **Read path** | Live Admin GraphQL API (costs points) | CCT cache (zero cost, <50ms) |
| **Write path** | Live Admin GraphQL mutations | Action Scheduler + WC CRUD |
| **Data freshness** | Real-time (live API) | Configurable (1–60 min + webhooks) |
| **Scale limit** | GraphQL bucket (1,000 pts) | No per-query limit |
| **Use case** | Ad-hoc operations, mutations | Bulk queries, analytics, dashboards |
| **API dependency** | Blocks on API failure | Serves stale cache on failure |

**Key Design Principle:** The sync tools do NOT replace the existing Shopify tools. They complement them. The live-API tools remain for real-time mutations and point queries. The sync tools serve bulk/analytics/cached-read use cases.

### 2.7 Admin Settings Page

**Class:** `WP_MCP_AI_Shopify_Sync_Toolkit_Settings_Page`  
**Extends:** `WP_MCP_AI_Toolkit_Settings_Base`  
**Menu:** Sub-menu under the existing E-commerce Toolkit or a new top-level "Shopify Sync" menu (icon `dashicons-update`, position 58)  
**Capability Gate:** `manage_woocommerce` for visibility; `manage_options` for configuration changes

**Tabs:**

| Tab | Content |
|---|---|
| **Overview** | Connection status per Shopify store (✅/❌), last sync timestamp, CCT row count, sync freshness indicator, GraphQL cost summary (daily points used/remaining), webhook registration status |
| **Configuration** | Sync interval (5/15/30/60 min), sync direction (Shopify→WC / Bidirectional / Read-Only), field mapping overrides, low-stock threshold, reorder-point calculation method, CCT slug override |
| **Sync Log** | Last 50 sync events with timestamp, duration, items synced, errors, cost consumed. Filterable by connection ID. Export to CSV. |
| **Webhooks** | Register/unregister webhooks per connection. Show webhook topic, delivery URL, last delivery status, HMAC verification test. Copy webhook URL for Shopify admin setup. |
| **Tools Management** | Enable/disable per-tool toggles, capability overrides. Inherited from `WP_MCP_AI_Toolkit_Settings_Base`. |

**WP-CLI Integration (Phase 2):**
```bash
wp shopify-sync status                    # Show sync status for all connections
wp shopify-sync trigger [connection_id]   # Force full sync
wp shopify-sync clear-cache [connection_id] # Clear CCT cache
wp shopify-sync register-webhooks [connection_id] # Register Shopify webhooks
wp shopify-sync cost-report               # Show 30-day GraphQL cost report
```

---

## 3. Implementation — Decisions Required

| # | Decision | Options | Recommendation |
|---|---|---|---|
| 1 | **Go / No-Go** | Proceed / Defer / Decline | **Proceed** — existing Shopify infra provides 90% of the API layer; sync is the missing piece |
| 2 | **CCT vs Custom Table** | CCT only / Custom table only / Both | **CCT only** — JetEngine provides admin UI, REST, relations for free; FlowHub validated the pattern |
| 3 | **Webhooks vs Polling-only** | Webhooks + polling / Polling only | **Webhooks + polling** — webhooks for real-time (0 pts), polling as safety net |
| 4 | **Bidirectional WC sync?** | Implement now / Phase 2 / Defer | **Phase 1: Shopify→WC only.** Bidirectional requires conflict resolution engine; scope it separately. |
| 5 | **Orders in CCT?** | Cache orders / Don't cache orders | **Cache order headers only** (ID, status, total, customer, date). Full order detail still hits API (privacy-sensitive data in PII fields). |
| 6 | **Multi-connection support?** | All connections / Per-connection toggle | **Per-connection toggle.** Admin selects which Shopify connections to sync. Each connection gets its own Action Scheduler group. |
| 7 | **WooCommerce product auto-creation?** | Auto-create WC products from Shopify / Manual only | **Manual + assisted.** Provide a tool action (`create_woo_product`) that creates a WC product from a cached Shopify product, but never auto-create (avoids catalog pollution). |

---

## 4. Benefits

### 4.1 For End Users (WooCommerce + Shopify Merchants)
- **Zero-cost AI inventory queries** — "Which products are below reorder point across all locations?" costs 0 GraphQL points
- **Sub-50ms response time** — CCT reads are orders of magnitude faster than live API calls
- **Automated cross-platform stock sync** — Sell on both WooCommerce and Shopify? Stock stays synchronized
- **Natural-language commerce analytics** — "Compare stock velocity of Nike products vs Adidas at the warehouse location"
- **Webhook-powered real-time updates** — Inventory changes on Shopify reflect in WooCommerce within seconds (when webhooks are configured)
- **Single admin surface** — Manage Shopify sync alongside other NV oOS toolkits

### 4.2 For NV oOS
- **Second external-API sync toolkit** — Validates the FlowHub CCT-cache pattern as reusable
- **GraphQL cost optimization** — Demonstrates sophisticated API cost management (bulk operations, webhooks, delta sync)
- **Pro subscription driver** — Multi-channel merchants are high-value customers
- **WP-CLI story** — Adds `wp shopify-sync` commands, strengthening the CLI surface
- **Minimal new code** — Reuses existing `WP_MCP_AI_Shopify_Client` (1,390 lines of battle-tested API code) unchanged

### 4.3 For Developers
- **Reusable sync engine pattern** — `WP_MCP_AI_External_Sync_Engine` can be abstracted for any REST/GraphQL API
- **Webhook HMAC verification** — Reference implementation for future webhook-based toolkits
- **JetEngine CCT auto-provisioning** — Pattern for column auto-creation that future toolkits can copy

---

## 5. Effort Estimation

| Phase | Description | Duration | Stories |
|---|---|---|---|
| **Phase 1: Foundation** | CCT Manager, CCT schema + auto-creation, sync engine skeleton, Action Scheduler hooks, admin page shell, toolkit toggle | 1 week | 4 |
| **Phase 2: Core Sync** | Bulk operation sync, delta sync (updated_at), CCT upsert with hash change detection, WC stock writeback, error notification | 1.5 weeks | 5 |
| **Phase 3: Tools** | 5 AI-callable tools (inventory, products, orders, settings, analytics), connection resolver trait, CCT query builders, canonical envelopes | 1.5 weeks | 5 |
| **Phase 4: Webhooks** | REST endpoint, HMAC verification, topic routing, per-connection registration, webhook status admin UI | 1 week | 3 |
| **Phase 5: Polish** | WP-CLI commands, GraphQL cost dashboard, admin email alerts, PHPUnit tests, tool reference docs, README, PR review | 1 week | 4 |
| **Total** | | **6 weeks** | **21** |

**Team:** 1 senior PHP developer + code review by agent.

---

## 6. Success Metrics

| Metric | Target | Measurement |
|---|---|---|
| CCT read latency | < 50ms for 10K rows | Query Monitor / New Relic |
| Bulk sync throughput | 1,000 products/second (JSONL parse + CCT upsert) | Sync log timestamps |
| Webhook processing time | < 2 seconds (Shopify 5-second timeout) | Webhook delivery log |
| GraphQL cost per full sync | ≤ 20 points (10 bulk + 10 order bulk) | Shopify cost telemetry |
| Tool execution time (CCT read) | < 500ms for filtered query | WP_MCP_AI execution history |
| Test coverage | > 80% line coverage on CCT manager, sync engine, tools | PHPUnit coverage report |
| Error recovery | 100% of transient API failures retried successfully | Sync log error → retry → success rate |

---

## 7. Risks & Mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| **Shopify API version deprecation** breaks queries | Medium | Pin API version per connection; admin UI warns when version >12 months old; `LATEST_KNOWN_VERSION` constant updated quarterly |
| **JetEngine CCT not installed** | Medium | Graceful degradation — tools return clear `WP_Error`; admin notice with install link; CCT is optional (tools can fall back to live API reads at higher cost) |
| **Large catalogs (50K+ SKUs, 10+ locations)** | Low | Bulk operations handle arbitrary size; CCT indexed queries scale linearly; webhooks keep deltas small |
| **GraphQL cost bucket exhaustion** | Medium | Daily cost budget tracker in admin; bulk operations minimize cost; webhooks cost zero; throttle AI tool `refresh` actions when budget < 20% |
| **Webhook endpoint not publicly accessible** (local/dev environments) | Medium | Webhooks are optional; polling-based sync works without them; admin UI shows "webhooks unavailable" status clearly |
| **WooCommerce HPOS incompatibility** | Low | All WC writes use CRUD (`wc_update_product_stock()`, `$product->set_stock_quantity()`); no direct `post_meta` writes |

---

## 8. Differences from the FlowHub Pro Toolkit (PR #5501)

| Aspect | FlowHub Toolkit | Shopify Sync Toolkit |
|---|---|---|
| **Source** | Absorbed standalone plugin | New build on existing `WP_MCP_AI_Shopify_Client` |
| **API type** | REST (simple `wp_remote_get`) | GraphQL (complex query construction, cost model) |
| **Sync mechanism** | Polling only (Action Scheduler) | Webhooks + bulk operations + polling |
| **Pagination** | REST offset/limit | GraphQL cursor-based + JSONL bulk |
| **Change detection** | `sync_hash` comparison | `sync_hash` + `updated_at` timestamp delta |
| **Cost management** | N/A (free API) | GraphQL cost model telemetry + budget tracking |
| **Migration path** | Detect and import from standalone plugin | No source plugin to migrate; new standalone feature |
| **CCT columns** | 25 cannabis-specific fields | 25 commerce-generic fields |
| **WC integration** | `wc_update_product_stock()` (same) | Same + `wc_get_product_id_by_sku()` matching |
| **Webhooks** | N/A | Webhook registration + HMAC verification |
| **WP-CLI** | N/A | `wp shopify-sync` commands |

---

## 9. Appendix A: Option Key Reference

| Option Key | Type | Default | Purpose |
|---|---|---|---|
| `wp_mcp_ai_settings[enable_shopify_sync_toolkit]` | bool | `false` | Master toolkit toggle |
| `wp_mcp_ai_shopify_sync_toolkit_settings[sync_interval]` | int | `15` | Minutes between syncs |
| `wp_mcp_ai_shopify_sync_toolkit_settings[sync_direction]` | string | `'shopify_to_woo'` | Sync direction |
| `wp_mcp_ai_shopify_sync_toolkit_settings[enable_wc_sync]` | bool | `false` | Enable WC stock writeback |
| `wp_mcp_ai_shopify_sync_toolkit_settings[enable_webhooks]` | bool | `true` | Enable webhook registration |
| `wp_mcp_ai_shopify_sync_toolkit_settings[cct_slug]` | string | `'shopify_inventory_sync'` | CCT slug |
| `wp_mcp_ai_shopify_sync_toolkit_settings[low_stock_threshold]` | int | `5` | Low-stock badge threshold |
| `wp_mcp_ai_shopify_sync_toolkit_settings[field_mapping]` | array | `[]` | Custom field mapping |
| `wp_mcp_ai_shopify_sync_toolkit_settings[sync_connections]` | array | `[]` | Connection IDs to sync |
| `wp_mcp_ai_shopify_last_sync_{conn_id}` | string | `''` | Per-connection last sync timestamp |
| `wp_mcp_ai_shopify_last_sync_error_{conn_id}` | string | `''` | Per-connection last error |
| `wp_mcp_ai_shopify_daily_cost_{conn_id}` | object | `{}` | Daily GraphQL cost tracking |
| `wp_mcp_ai_shopify_webhook_registered_{conn_id}` | bool | `false` | Webhook registration status |

## Appendix B: File Manifest

```
New files (~15):
├── addons/pro/includes/
│   ├── class-wp-mcp-ai-shopify-sync-cct-manager.php       # CCT cache management
│   ├── class-wp-mcp-ai-shopify-sync-engine.php             # Action Scheduler + webhook orchestration
│   ├── class-wp-mcp-ai-shopify-sync-webhook-handler.php     # REST endpoint + HMAC verification
│   └── admin/
│       └── class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php  # Admin UI
├── addons/pro/includes/tools/shopify-sync/
│   ├── README.md
│   ├── init.php
│   ├── trait-wp-mcp-ai-shopify-sync-connection-resolver.php
│   ├── class-wp-mcp-ai-pro-tool-shopify-sync-inventory.php
│   ├── class-wp-mcp-ai-pro-tool-shopify-sync-products.php
│   ├── class-wp-mcp-ai-pro-tool-shopify-sync-orders.php
│   ├── class-wp-mcp-ai-pro-tool-shopify-sync-settings.php
│   └── class-wp-mcp-ai-pro-tool-shopify-sync-analytics.php
└── tests/
    ├── test-shopify-sync-cct-manager.php
    ├── test-shopify-sync-engine.php
    └── test-shopify-sync-tools.php

Modified files (2):
├── addons/pro/mcp-ai-wpoos-pro.php    ← wp_mcp_ai_pro_register_tools()
└── docs/reference/tools/tool-reference.md  ← Tool entries
```

## Appendix C: Webhook Endpoint Specification

```
POST /wp-json/mcp-ai/v1/shopify/webhook

Headers:
  X-Shopify-Hmac-SHA256: <base64-encoded-HMAC>
  X-Shopify-Shop-Domain: <store>.myshopify.com
  X-Shopify-Topic: products/update | products/delete | inventory_levels/update
  X-Shopify-API-Version: 2025-01
  Content-Type: application/json

Body: (varies by topic)

Authentication:
  HMAC-SHA256( request_body, client_secret ) compared to X-Shopify-Hmac-SHA256
  Reject with 401 if mismatch.

Response:
  200 OK — Webhook processed
  401 Unauthorized — HMAC verification failed
  500 Internal Server Error — Processing error (Shopify will retry)
```

## Appendix D: Related Documents

- [FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md](./FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md) — Reference architecture
- [FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md](./FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md) — Reference implementation plan
- [FIREFLY-III-INTEGRATION-PROPOSAL.md](./FIREFLY-III-INTEGRATION-PROPOSAL.md) — Reference proposal format
- [`CLAUDE.md`](../../CLAUDE.md) — Tool implementation pattern, return envelope, sanitisation rules
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — Credential handling, HMAC verification
- [`.context/wp-action-scheduler.md`](../../.context/wp-action-scheduler.md) — Action Scheduler patterns
- [`.context/wp-plugin-lifecycle.md`](../../.context/wp-plugin-lifecycle.md) — Activation/deactivation cleanup
- [`.context/wp-rest-api.md`](../../.context/wp-rest-api.md) — REST API endpoint conventions
