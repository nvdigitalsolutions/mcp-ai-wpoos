# FlowHub Inventory Sync — Pro Toolkit Integration Proposal

**Date:** June 28, 2026  
**Status:** 📋 PROPOSAL  
**Plugin Target:** NV oOS Pro v1.2.0+  
**Source Plugin:** flowhub-inventory-sync v1.6 (private repo)  
**Related:** [FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md](./FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md)

---

## Executive Summary

Proposal to absorb **FlowHub Inventory Sync** — a WordPress plugin that synchronizes cannabis dispensary inventory from the FlowHub POS/API into WooCommerce — as a first-class **Pro Toolkit** within the NV oOS (Open Operator System) plugin. The integration replaces raw WP-Cron + custom table architecture with **JetEngine CCT-based smart caching**, **Action Scheduler** for background sync, and five AI-callable tools that read locally (never hitting the API per query). The existing `[fis_inventory]` React SPA frontend is preserved as an optional shortcode, but the primary value shifts to making FlowHub inventory **queryable by LLM agents** and **automatable through the NV oOS orchestration engine**.

**Key Recommendation:** Proceed as a Pro Toolkit with CCT cache layer.

**Value Proposition:** Cannabis retailers using WooCommerce + FlowHub gain AI-powered inventory queries, automated stock alerts, bidirectional WC↔FH sync, and natural-language reporting — all without leaving their WordPress admin. NV oOS gains a differentiated vertical toolkit for the fast-growing regulated cannabis market.

---

## 1. Research Summary — Industry Standards

### 1.1 The Cannabis Retail Technology Landscape

FlowHub is the leading cannabis dispensary POS in the US, serving 1,000+ dispensaries across 15+ states. Key characteristics that shape the integration architecture:

| Reality | Architectural Implication |
|---|---|
| **High-volume inventory churn** (200–2,000 SKUs per store) | API polling must be efficient; local cache essential |
| **Multi-location operations** | Data must be sharded by `location_id` in CCT |
| **State-by-state compliance rules** (Metrc, Biotrack) | Field mapping must be configurable, not hard-coded |
| **Non-zero inventory filter** (API already applies `inventoryNonZero`) | Cache represents "currently sellable" view, not full history |
| **Regulated industry — no unauthenticated access** | Tools require `manage_woocommerce` capability minimum |
| **Open REST API with API key auth** | Standard `X-Api-Key` header pattern; no OAuth complexity |
| **Real-time menu accuracy is critical for compliance** | Sync interval must be configurable down to 1 minute |

### 1.2 POS-to-WooCommerce Sync Patterns (Industry Best Practices)

Analysis of leading WooCommerce POS plugins (ConnectPOS, Oliver POS, WePOS, FooSales) reveals a consistent architecture:

```
External POS API ──(scheduled poll)──▶ Local Sync Table
                                            │
                              ┌─────────────┼─────────────┐
                              ▼             ▼             ▼
                        WooCommerce    Admin UI      Frontend SPA
                        (wc_update_)   (wp-admin)   (shortcode)
```

**Key industry patterns observed:**

1. **Cache-first architecture** — All major plugins maintain a local sync table. None query the POS API on every page load. Sync intervals range from 1–15 minutes for high-volume stores, 30–60 minutes for low-volume.

2. **Idempotent sync** — Every sync operation is designed to be safe to run repeatedly. Records are upserted (matched on `product_id` + `location_id` compound key), not blindly inserted.

3. **Non-destructive writes** — POS sync writes to the local cache only. It never overwrites WooCommerce product data that a store manager has manually curated (descriptions, images, pricing overrides).

4. **Directional configuration** — Most plugins support three modes: POS→WooCommerce (one-way), WooCommerce→POS (one-way), or bidirectional with conflict-resolution rules.

5. **Error isolation** — A failed POS API call does not break the WooCommerce storefront. Stale cached data is always better than no data.

6. **HPOS compatibility** — Modern plugins use WooCommerce CRUD (`wc_get_product()`, `$product->set_stock_quantity()`) rather than direct `post_meta` writes, ensuring HPOS (High-Performance Order Storage) compatibility.

### 1.3 CCT as Smart Cache — Rationale

The source plugin already uses a JetEngine CCT for the frontend SPA. This integration doubles down on that decision because:

| Criterion | CCT | Custom Table (`wp_flowhub_sync_data`) | WordPress Transients |
|---|---|---|---|
| **Query performance** | ✅ Indexed, WP_Query-compatible | ✅ Indexed | ⚠️ Serialized blob, no indexing |
| **Admin UI (list table)** | ✅ JetEngine provides free admin list | ❌ Must build custom | ❌ Not applicable |
| **REST API** | ✅ Auto-exposed by JetEngine | ❌ Must build custom | ❌ Not applicable |
| **Field evolution** | ✅ JetEngine handles column creation | ⚠️ Manual `dbDelta` on update | ❌ Not applicable |
| **Relation support** | ✅ JetEngine Relations (e.g., CCT↔Product) | ❌ Manual JOIN queries | ❌ Not applicable |
| **Multi-site** | ✅ Per-site tables (JetEngine native) | ⚠️ Must handle `$wpdb->prefix` | ⚠️ Shared if network-activated |
| **Data volume** | ✅ Handles 10K–100K rows | ✅ Handles 10K–100K rows | ❌ Degrades above ~100 rows |

**Decision:** Use JetEngine CCT exclusively. Drop the `wp_flowhub_sync_data` custom table from the source plugin. The CCT provides the same storage role with dramatically less custom code.

### 1.4 Action Scheduler vs. WP-Cron

The source plugin uses raw WP-Cron with 5 custom intervals (`fis_1min` through `fis_60min`). Industry best practice for e-commerce workloads strongly favors Action Scheduler:

| Criterion | WP-Cron | Action Scheduler |
|---|---|---|
| **Concurrency guard** | ❌ No built-in lock; overlapping syncs possible | ✅ `unique` parameter prevents overlap |
| **Retry on failure** | ❌ Silent failure | ✅ Configurable retry with backoff |
| **Admin visibility** | ❌ Requires WP Crontrol plugin | ✅ Built-in admin table + WP-CLI |
| **Scale** | ⚠️ Degrades above ~100 scheduled hooks | ✅ Handles thousands of actions |
| **WooCommerce integration** | ❌ Separate system | ✅ Bundled with WooCommerce (zero dependency) |
| **Bulk cleanup** | `wp_clear_scheduled_hook()` | `as_unschedule_all_actions()` |

**Decision:** Migrate from 5 WP-Cron hooks to a single Action Scheduler recurring action with a configurable interval. Use `as_schedule_recurring_action()` with `unique` parameter.

---

## 2. Proposed Architecture

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│  ADMIN UI                                                    │
│  FlowHub Toolkit Settings Page                               │
│  (extends WP_MCP_AI_Toolkit_Settings_Base)                   │
├─────────────────────────────────────────────────────────────┤
│  TOOLS (LLM-facing)                                          │
│  flowhub_inventory │ flowhub_products │ flowhub_locations    │
│  flowhub_sync      │ flowhub_settings                       │
│  (read CCT; write via sync engine; canonical envelope)       │
├─────────────────────────────────────────────────────────────┤
│  SYNC ENGINE                                                 │
│  WP_MCP_AI_FlowHub_Sync_Engine                               │
│  → Action Scheduler: wp_mcp_ai_flowhub_full_sync             │
│  → Action Scheduler: wp_mcp_ai_flowhub_wc_sync (optional)    │
├──────────────────┬──────────────────────────────────────────┤
│  FLOWHUB CLIENT  │  CCT MANAGER                              │
│  (API calls)     │  (local cache read/write)                 │
│  → GET /v0/      │  → JetEngine CCT: flowhub_inventory      │
│    inventory     │  → Auto-create columns                   │
│    NonZero       │  → Truncate + upsert                     │
└──────┬───────────┴──────────────┬───────────────────────────┘
       │                          │
       ▼                          ▼
┌──────────────┐    ┌─────────────────────────┐
│ FlowHub API  │    │ WooCommerce              │
│ (external)   │    │ wc_update_product_stock()│
└──────────────┘    └─────────────────────────┘
```

### 2.2 Data Flow (Read Path)

```
LLM Agent queries "show me all Flower products in stock"
        │
        ▼
flowhub_inventory tool (execute)
        │
        ├─ Check capability: manage_woocommerce
        ├─ Sanitize arguments (Gate 1)
        ├─ Check CCT freshness (< sync_interval old?)
        │   ├─ Fresh → CCT_Manager::get_cached_items(filters)
        │   └─ Stale  → Sync_Engine::run_delta_sync() → then CCT query
        ├─ CCT_Manager returns normalized array
        ├─ Escape output (Gate 2)
        └─ Return canonical envelope {success, message, data}
```

### 2.3 Data Flow (Write/Sync Path)

```
Action Scheduler fires wp_mcp_ai_flowhub_full_sync
        │
        ▼
Sync_Engine::run_full_sync()
        │
        ├─ FlowHub_Client::get_inventory(page=1)
        │   └─ GET api.flowhub.co/v0/inventoryNonZero
        │       Headers: X-Api-Client-Id, X-Api-Key
        │       Response: { data: [{...}, {...}], total_pages: N }
        │
        ├─ Paginate through all pages (rate-limited, 100 items/page)
        │
        ├─ For each item:
        │   ├─ Map FlowHub fields → CCT columns (via field mapping config)
        │   ├─ Extract images/prices from item_data JSON
        │   ├─ Compute sync_hash = md5(json_encode(item))
        │   └─ Upsert into JetEngine CCT (match on product_id + location_id)
        │
        ├─ (If WC sync enabled) For each item:
        │   ├─ Match to WooCommerce product by SKU
        │   ├─ Update stock_quantity via wc_update_product_stock()
        │   └─ Store linked woo_product_id in CCT row
        │
        ├─ Update option: wp_mcp_ai_flowhub_last_sync → now()
        ├─ Log sync event to wp_mcp_ai_recent_activity
        └─ Fire hook: wp_mcp_ai_flowhub_after_sync
```

### 2.4 CCT Schema

**CCT Slug:** `flowhub_inventory` (overridable in settings)  
**Primary Key Strategy:** Compound uniqueness on `(product_id, location_id)` enforced at application layer.

| Column | Type | Source | Notes |
|---|---|---|---|
| `product_id` | TEXT | `item.productId` | FlowHub internal ID |
| `variant_id` | TEXT | `item.variantId` | Nullable — blank for non-variant products |
| `parent_product_id` | TEXT | `item.parentProductId` | For variant grouping |
| `sku` | TEXT | `item.sku` | Primary WooCommerce match key |
| `product_name` | TEXT | `item.productName` | |
| `variant_name` | TEXT | `item.variantName` | |
| `category` | TEXT | `item.category` | e.g., "Flower", "Edible", "Concentrate" |
| `custom_category_name` | TEXT | `item.customCategoryName` | Dispensary-defined category |
| `purchase_category` | TEXT | `item.purchaseCategory` | |
| `product_description` | TEXT | `item.productDescription` | Plain text (FlowHub) |
| `quantity` | INT | `item.quantity` | Non-zero (API filter) |
| `location_id` | TEXT | `item.locationId` | FlowHub location UUID |
| `location_name` | TEXT | `item.locationName` | Human-readable location |
| `unit_of_measure` | TEXT | `item.inventoryUnitOfMeasure` | e.g., "each", "gram", "ounce" |
| `image_url` | TEXT | Extracted from `item_data` | First image URL parsed from JSON |
| `price` | DECIMAL(10,2) | Extracted from `item_data` | Unit price parsed from JSON |
| `woo_product_id` | INT | Set by WC sync | Links to `wp_posts.ID` (product) |
| `last_updated` | DATETIME | `current_time('mysql')` | Set on each sync |
| `item_data` | LONGTEXT | Full JSON payload | Complete FlowHub response for future extraction |
| `sync_status` | TEXT | Set by sync engine | `synced` / `pending` / `error` / `stale` |
| `sync_hash` | TEXT | `md5(json_encode($item))` | Change detection (skip unchanged rows) |

**Column Auto-Creation:** Before each sync, `CCT_Manager::ensure_columns()` checks the JetEngine CCT schema and auto-creates any missing columns via `jet_engine()->cct->add_field()`. This matches the source plugin's behavior (v1.4).

### 2.5 Tool Definitions

All tools implement `WP_MCP_AI_Tool_Interface` + `WP_MCP_AI_Tool_Capability_Flags_Interface` and use `WP_MCP_AI_FlowHub_Connection_Resolver` trait.

| Tool Slug | Class | Action Enum | CCT-First? | Capability |
|---|---|---|---|---|
| `flowhub_inventory` | `WP_MCP_AI_Pro_Tool_FlowHub_Inventory` | `search`, `get_item`, `get_levels`, `refresh` | Yes (read) | `manage_woocommerce` |
| `flowhub_products` | `WP_MCP_AI_Pro_Tool_FlowHub_Products` | `search`, `get_product`, `get_by_sku`, `list_categories` | Yes (read) | `manage_woocommerce` |
| `flowhub_locations` | `WP_MCP_AI_Pro_Tool_FlowHub_Locations` | `list`, `get_location` | Yes (read) | `manage_woocommerce` |
| `flowhub_sync` | `WP_MCP_AI_Pro_Tool_FlowHub_Sync` | `sync_now`, `sync_status`, `clear_cache` | No (write) | `manage_options` |
| `flowhub_settings` | `WP_MCP_AI_Pro_Tool_FlowHub_Settings` | `get_settings`, `update_settings`, `test_connection`, `get_field_mapping` | No (admin) | `manage_options` |

**Capability Flags for All Tools:**
```php
array(
    'pro',                  // Pro tier
    'external-api',         // Talks to FlowHub
    'requires-credentials', // Needs API keys
    'requires-capability',  // WP capability check
)
```

### 2.6 Admin Settings Page

**Class:** `WP_MCP_AI_FlowHub_Toolkit_Settings_Page`  
**Extends:** `WP_MCP_AI_Toolkit_Settings_Base`  
**Menu:** Top-level, icon `dashicons-store`, position 57 (after E-commerce)  
**Capability Gate:** `manage_woocommerce` for visibility; `manage_options` for configuration changes

**Tabs (inherited from base):**

| Tab | Content |
|---|---|
| **Overview** | Connection status badge (✅/❌), last sync timestamp, CCT row count, next scheduled sync, WooCommerce link status, FlowHub store name |
| **Configuration** | API credentials section (via Remote Sites), sync interval (1/5/15/30/60 min), sync direction (FH→WC / WC→FH / bidirectional), low stock threshold, field mapping presets |
| **CCT Status** | CCT slug, row count, column listing, "Force Full Sync" button, "Clear Cache & Resync" button, last 10 sync errors |
| **Tools Management** | Enable/disable per-tool toggles, capability overrides, tool description previews |
| **Help** | FlowHub API documentation links, Metrc compliance notes, troubleshooting guide |

---

## 3. Decisions Required

| # | Decision | Options | Recommendation |
|---|---|---|---|
| 1 | **Go / No-Go** on FlowHub Pro Toolkit | Proceed / Defer / Decline | **Proceed** — clear market need, well-defined scope |
| 2 | **CCT vs Custom Table** for local cache | CCT only / Custom table + CCT / Custom table only | **CCT only** — JetEngine provides admin UI, REST, relations for free |
| 3 | **Keep React SPA shortcode?** | Keep `[fis_inventory]` / Replace with Block / Drop entirely | **Keep as optional** — preserve source plugin's frontend while tools serve AI use cases |
| 4 | **Action Scheduler vs WP-Cron** | Action Scheduler / Keep WP-Cron / Hybrid | **Action Scheduler** — bundled with WooCommerce, better concurrency |
| 5 | **Bidirectional WC sync?** | Implement now / Phase 2 / Defer | **Phase 2** — one-way FH→CCT first; WC writeback is complex (conflict resolution) |
| 6 | **Cannabis compliance features?** | Metrc reporting hooks / Audit log / Defer | **Audit log now** — sync events already logged; Metrc hooks deferred |
| 7 | **Source plugin migration path?** | Auto-detect + offer migration / Manual / No migration | **Auto-detect** — admin notice if flowhub-inventory-sync is active, offer one-click migration |

---

## 4. Benefits

### 4.1 For End Users (Dispensary Operators)
- **AI-powered inventory queries** — "Which locations have Blue Dream below 10 units?"
- **Automated low-stock alerts** — Configurable thresholds per product/category
- **Natural language reporting** — "Show me sales velocity for edibles at the Main St. location this week"
- **Single admin surface** — FlowHub settings live alongside other NV oOS toolkits
- **No API latency per query** — All reads hit the local CCT cache

### 4.2 For NV oOS
- **First cannabis-vertical toolkit** — Differentiated offering in a fast-growing regulated market
- **Reusable CCT-cache pattern** — Establishes a template for other external API sync toolkits (e.g., future Square POS, Lightspeed, etc.)
- **Pro subscription driver** — Cannabis retailers are high-value, low-churn customers
- **Minimal new patterns** — Everything follows existing conventions (Toolkit_Settings_Base, Remote Sites, Action Scheduler)

### 4.3 For the Source Plugin Users
- **Migration path** — Existing FlowHub Inventory Sync users can adopt the Pro toolkit
- **No data loss** — CCT structure is compatible; existing CCT rows are preserved
- **Enhanced capabilities** — AI tools, better scheduling, observability, and orchestration

---

## 5. Effort Estimation

| Phase | Description | Duration | Stories |
|---|---|---|---|
| **Phase 1: Foundation** | Client class, CCT manager, admin page shell, toolkit toggle | 1 week | 3 |
| **Phase 2: Core Tools** | 5 tool classes with CCT-first read paths, connection resolver trait | 1.5 weeks | 5 |
| **Phase 3: Sync Engine** | Action Scheduler integration, Field mapping, WC stub, sync log | 1 week | 4 |
| **Phase 4: Testing & Docs** | PHPUnit, capability tests, sync integration tests, README, tool reference | 1 week | 4 |
| **Phase 5: Migration & Polish** | Source plugin migration path, admin notice, compliance log, final review | 0.5 weeks | 3 |
| **Total** | | **5 weeks** | **19** |

**Team:** 1 senior PHP developer + code review by agent.

---

## 6. Success Metrics

| Metric | Target | Measurement |
|---|---|---|
| CCT read latency | < 50ms for 10K rows | Query monitor / New Relic |
| Sync throughput | 500 items/second | Sync log timestamps |
| Tool execution time | < 2s (CCT read) / < 30s (API refresh) | WP_MCP_AI execution history |
| Test coverage | > 80% line coverage on client, CCT manager, tools | PHPUnit coverage report |
| Migration success rate | > 95% of existing settings auto-imported | Migration wizard log |
| Admin notice for broken sync | < 60s after sync failure | `fis_notify_error` equivalent |

---

## 7. Risks & Mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| **FlowHub API changes break sync** | Medium | Version-pin API; health-check before each sync; alert on schema mismatch |
| **JetEngine CCT not installed** | Medium | Graceful degradation — tools return clear error; admin notice with install link |
| **Large dispensaries (10K+ SKUs, 5+ locations)** | Low | Paginated sync; incremental delta sync (compare `last_updated`); configurable batch size |
| **Cannabis regulatory changes (state-level)** | Low | Field mapping is configurable, not hard-coded; Metrc hooks are deferred to Phase 2 |
| **Source plugin users resist migration** | Low | Keep React SPA shortcode; one-click migration preserves all data |

---

## 8. Related Documents

- [FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md](./FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md) — Detailed implementation plan
- [FIREFLY-III-INTEGRATION-PROPOSAL.md](./FIREFLY-III-INTEGRATION-PROPOSAL.md) — Reference proposal format
- [UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md](./UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md) — Two-gate sanitisation, canonical envelope
- [PRO_PLUGIN_ENHANCEMENT_EXECUTIVE_SUMMARY.md](./PRO_PLUGIN_ENHANCEMENT_EXECUTIVE_SUMMARY.md) — Pro toolkit architecture context
- [Templates directory](./templates/) — Proposal & implementation plan templates
- [`CLAUDE.md`](../../CLAUDE.md) — Tool implementation pattern, return envelope, sanitisation rules
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — Credential handling, token storage
- [`.context/wp-action-scheduler.md`](../../.context/wp-action-scheduler.md) — Action Scheduler patterns
- [`.context/wp-plugin-options-storage.md`](../../.context/wp-plugin-options-storage.md) — Options storage patterns
- [`.context/wp-plugin-lifecycle.md`](../../.context/wp-plugin-lifecycle.md) — Activation/deactivation cleanup
