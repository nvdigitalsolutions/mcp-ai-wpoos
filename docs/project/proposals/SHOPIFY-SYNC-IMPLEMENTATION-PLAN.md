# Shopify Sync — Pro Toolkit Implementation Plan

**Date:** June 28, 2026  
**Status:** 📋 PENDING APPROVAL  
**Related:** [SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md](./SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md)  
**Reference Architecture:** [FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md](./FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md)  
**Target Release:** NV oOS Pro v1.3.0  
**Estimated Duration:** 6 weeks (21 stories)

---

## Executive Summary

This document provides a phased, story-level implementation plan for the Shopify Sync Pro Toolkit. Each phase is self-contained and testable. Phases 1–3 produce a working toolkit; Phases 4–5 harden, document, and add webhook support. Unlike the FlowHub toolkit (which absorbed an existing standalone plugin), this toolkit is a **new build on the existing `WP_MCP_AI_Shopify_Client`** — the 1,390-line Shopify GraphQL client is reused unchanged.

---

## Pre-Implementation Checklist

- [ ] Audit existing `WP_MCP_AI_Shopify_Client` for bulk operation and webhook readiness (graphql, bulk_query, get_inventory_levels, adjust_inventory, get_locations already exist)
- [ ] Verify Shopify Bulk Operation API endpoint contracts (mutation `bulkOperationRunQuery`, poll status query, JSONL download)
- [ ] Verify Shopify webhook topic schema: `products/update`, `products/delete`, `inventory_levels/update`
- [ ] Confirm JetEngine CCT column auto-creation API (`jet_engine()->cct->add_field()`) — validated via FlowHub PR #5501
- [ ] Confirm Action Scheduler availability (bundled with WooCommerce)
- [ ] Confirm `wc_update_product_stock()` HPOS compatibility
- [ ] Identify test Shopify store (development store via Partners dashboard) with API credentials
- [ ] Set up local WooCommerce + JetEngine + NV oOS Pro development environment
- [ ] Verify `WP_MCP_AI_Pro_Remote_Site_Manager::get_connection()` works for Shopify connection type
- [ ] Review Shopify GraphQL cost model: 1,000-point bucket, 50 pts/sec refill, bulk operations = 10 pts flat

---

## Phase 1: Foundation (Week 1 — Stories 1–4)

**Goal:** CCT manager, schema, sync engine skeleton, admin page, and toolkit toggle are functional. No tools yet. No webhooks yet.

### Story 1.1 — CCT Manager (Schema & CRUD)

**File:** `addons/pro/includes/class-wp-mcp-ai-shopify-sync-cct-manager.php`

**Implementation:**
```php
class WP_MCP_AI_Shopify_Sync_CCT_Manager {
    const CCT_SLUG_DEFAULT = 'shopify_inventory_sync';

    protected $cct_slug;
    protected $connection_id;

    public function __construct( $connection_id = null );

    // Schema management:
    public function get_cct_slug();
    public function set_cct_slug( $slug );
    public function ensure_columns();                 // Auto-create missing CCT columns
    public function get_column_definitions();          // Returns the columns array
    public function is_cct_available();                // JetEngine + CCT exists check

    // Read operations (CCT queries, no API calls):
    public function get_cached_items( $filters = array() );   // Search/filter/sort/paginate
    public function get_cached_item( $identifier, $by = 'sku' );
    public function get_cached_item_by_variant_id( $variant_id, $location_id = null );
    public function get_row_count();
    public function get_distinct_values( $column );           // Categories, vendors, types
    public function get_last_sync_time();
    public function is_fresh( $max_age_seconds = 900 );

    // Write operations:
    public function upsert( array $shopify_row, array $mapping );
    public function bulk_upsert_from_jsonl( array $jsonl_items, array $mapping, callable $progress = null );
    public function truncate();
    public function mark_stale();
    public function delete_item( $cct_item_id );
    public function update_woo_product_id( $cct_item_id, $woo_product_id );

    // Sync orchestration (delegates to existing WP_MCP_AI_Shopify_Client):
    public function sync_from_bulk_operation( callable $progress = null );
    public function sync_single_product( $product_gid );
    public function sync_inventory_delta( $variant_id, $location_id );
}
```

**CCT Columns (25 commerce-generic fields):**
```php
protected $columns = array(
    'shopify_product_id'    => 'text',
    'shopify_variant_id'    => 'text',
    'inventory_item_id'     => 'text',
    'sku'                   => 'text',
    'product_title'         => 'text',
    'variant_title'         => 'text',
    'product_type'          => 'text',
    'vendor'                => 'text',
    'tags'                  => 'text',
    'status'                => 'text',
    'location_id'           => 'text',
    'location_name'         => 'text',
    'available_qty'         => 'number',
    'on_hand_qty'           => 'number',
    'incoming_qty'          => 'number',
    'reserved_qty'          => 'number',
    'price'                 => 'number',
    'compare_at_price'      => 'number',
    'image_url'             => 'text',
    'handle'                => 'text',
    'woo_product_id'        => 'number',
    'woo_variation_id'      => 'number',
    'shopify_updated_at'    => 'datetime',
    'last_synced_at'        => 'datetime',
    'sync_hash'             => 'text',
    'sync_status'           => 'text',
    'raw_data'              => 'textarea',
);
```

**Key patterns:**
- Compound key on `(shopify_variant_id, location_id)` for upsert matching
- `sync_hash = md5(json_encode(row))` for change detection — skip unchanged rows
- Bulk upsert from JSONL: parse line by line, compute hash, compare, upsert only changed
- Read queries use JetEngine CCT query API (`jet_engine()->cct->data->get_items()`)

**Tests:** `tests/test-shopify-sync-cct-manager.php`
- Test column auto-creation (mock `jet_engine()->cct->add_field()`)
- Test upsert logic (new item, existing item update by compound key, hash skip)
- Test bulk upsert from JSONL (100 items, 20 changed, 80 skipped)
- Test filter queries (by vendor, product_type, stock_status, search)
- Test freshness check (is_fresh with various ages)
- Test truncate
- Test graceful behavior when JetEngine is not active → returns `WP_Error`

**Acceptance Criteria:**
- [ ] CCT columns are auto-created on first sync call
- [ ] Upsert correctly handles new vs. existing items (matched on variant_id + location_id)
- [ ] `sync_hash` change detection skips unchanged rows (benchmark: < 5ms per row)
- [ ] Cached queries return correct results for all filter combinations
- [ ] `is_fresh()` returns correct boolean based on `last_sync` timestamp
- [ ] Missing JetEngine returns clear `WP_Error` with install link message
- [ ] Tests pass with mocked JetEngine APIs

---

### Story 1.2 — Sync Engine (Action Scheduler + Full Sync)

**File:** `addons/pro/includes/class-wp-mcp-ai-shopify-sync-engine.php`

**Implementation:**
```php
class WP_MCP_AI_Shopify_Sync_Engine {
    const HOOK_FULL_SYNC = 'wp_mcp_ai_shopify_full_sync';
    const HOOK_WC_SYNC   = 'wp_mcp_ai_shopify_wc_sync';
    const GROUP          = 'shopify_sync';
    const GROUP_WC       = 'shopify_sync_wc';

    protected $connection_id;

    public function __construct( $connection_id );

    public static function init();
    public static function schedule_recurring_syncs( $connection_id, $interval_minutes );
    public function run_full_sync();            // Bulk operation → JSONL → CCT upsert
    public function run_wc_sync();              // CCT → wc_update_product_stock()
    public static function clear_scheduled_actions( $connection_id );

    // Cost-aware sync strategy:
    public function select_sync_strategy();      // bulk_op / graphql_delta / skip
    public function get_daily_cost_used();       // GraphQL cost telemetry
    public function is_cost_budget_low();        // Warn when < 20% remaining
}
```

**Full Sync Flow (bulk operation path):**
```php
public function run_full_sync() {
    $client      = new WP_MCP_AI_Shopify_Client( $this->connection_id );
    $cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );

    // Step 1: Ensure CCT schema is ready.
    $cct_manager->ensure_columns();

    // Step 2: Run Shopify Bulk Operation to export all products + variants + inventory.
    $bulk_query = '{ products { edges { node { id title handle status vendor productType tags updatedAt
        variants { edges { node { id title sku price compareAtPrice
            inventoryItem { id }
            inventoryLevels { edges { node { quantities(names:["available","on_hand","incoming","reserved"]) { name quantity }
                location { id name } } } }
        } } }
        images(first:1) { edges { node { url } } }
    } } } } }';

    $result = $client->bulk_query( $bulk_query, true );
    // Returns: { bulk_operation_id, count, items: [...] }

    // Step 3: Upsert all items into CCT with hash change detection.
    $sync_result = $cct_manager->bulk_upsert_from_jsonl(
        $result['items'],
        $this->get_field_mapping()
    );

    // Step 4: Track GraphQL cost.
    $this->track_sync_cost( $this->connection_id, 10 ); // Bulk op = 10 pts flat

    // Step 5: Persist sync timestamp.
    update_option( "wp_mcp_ai_shopify_last_sync_{$this->connection_id}", current_time('mysql') );
    delete_option( "wp_mcp_ai_shopify_last_sync_error_{$this->connection_id}" );

    do_action( 'wp_mcp_ai_shopify_after_sync', $sync_result, $this->connection_id );

    return $sync_result;
}
```

**Multi-Connection Scheduling:**
```php
public static function schedule_all_syncs() {
    $settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
    $sync_connections = $settings['sync_connections'] ?? array();

    foreach ( $sync_connections as $conn_id ) {
        $hook = self::HOOK_FULL_SYNC . '_' . $conn_id;
        if ( ! as_has_scheduled_action( $hook ) ) {
            as_schedule_recurring_action(
                time() + 60,
                absint( $settings['sync_interval'] ?? 15 ) * 60,
                $hook,
                array( 'connection_id' => $conn_id ),
                self::GROUP,
                true
            );
        }
    }
}
```

**Deactivation Cleanup:**
```php
public static function clear_scheduled_actions( $connection_id ) {
    $hook = self::HOOK_FULL_SYNC . '_' . $connection_id;
    as_unschedule_all_actions( $hook, array(), self::GROUP );
    as_unschedule_all_actions( self::HOOK_WC_SYNC . '_' . $connection_id, array(), self::GROUP_WC );
}
```

**Tests:** `tests/test-shopify-sync-engine.php`
- Test full sync with mocked bulk operation response (JSONL with 50 products)
- Test multi-connection scheduling (3 connections, each gets its own hook)
- Test cost budget tracking (100 pts used, 900 remaining)
- Test `is_cost_budget_low()` returns true when < 20% remaining
- Test deactivation cleanup

**Acceptance Criteria:**
- [ ] Full sync using bulk operations successfully parses JSONL and upserts into CCT
- [ ] Each connection gets its own Action Scheduler hook (no cross-talk)
- [ ] Sync runs on the configured interval per connection
- [ ] No overlapping syncs (enforced by `unique` parameter)
- [ ] GraphQL cost is tracked per-connection per-day
- [ ] Deactivation cleans up all scheduled actions for all connections

---

### Story 1.3 — Admin Page Skeleton & Toolkit Toggle

**Files:**
- `addons/pro/includes/admin/class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php`
- `addons/pro/includes/tools/shopify-sync/init.php`

**Admin Page Implementation:**
```php
class WP_MCP_AI_Shopify_Sync_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {
    public function __construct() {
        $this->toolkit_slug     = 'shopify-sync';
        $this->toolkit_name     = __( 'Shopify Sync', 'mcp-ai-wpoos-pro' );
        $this->option_name      = 'wp_mcp_ai_shopify_sync_toolkit_settings';
        $this->page_slug        = 'wp-mcp-ai-shopify-sync-toolkit-settings';
        $this->parent_slug      = 'wp-mcp-ai-shopify-sync-toolkit';
        $this->has_remote_sites = false;   // Uses existing Shopify Remote Sites connections
        $this->icon             = 'dashicons-update';
        $this->has_research     = false;

        add_action( 'admin_menu', array( $this, 'add_top_level_menu' ), 26 );
        parent::__construct();
    }

    public function add_top_level_menu() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        add_menu_page(
            __( 'Shopify Sync', 'mcp-ai-wpoos-pro' ),
            __( 'Shopify Sync', 'mcp-ai-wpoos-pro' ),
            'manage_woocommerce',
            $this->parent_slug,
            array( $this, 'redirect_to_first_submenu' ),
            $this->icon,
            58
        );
    }

    protected function render_overview_tab() {
        // Per-connection status cards:
        // - Connection name, store domain, connection status (✅/❌)
        // - Last sync timestamp, CCT row count, sync freshness
        // - Today's GraphQL cost (pts used / 1,000)
        // - Webhook registration status
        // - "Sync Now" button per connection
    }

    protected function render_configuration_tab() {
        // - Multi-select: which Shopify connections to sync
        // - Sync interval (5/15/30/60 min)
        // - Sync direction: Shopify→WC / Read-Only
        // - Enable WC sync toggle
        // - Low stock threshold
        // - CCT slug override
        // - Field mapping overrides (admin-only)
    }

    protected function render_sync_log_tab() {
        // - Table: timestamp, connection, duration, items synced, errors, cost
        // - Filterable by connection ID
        // - Export to CSV
    }

    protected function render_webhooks_tab() {
        // - Per-connection webhook status
        // - Register/Unregister buttons
        // - Webhook URL display
        // - HMAC verification test
    }

    protected function get_tools_list() {
        return array(
            'shopify_sync_inventory' => __( 'Shopify Sync Inventory', 'mcp-ai-wpoos-pro' ),
            'shopify_sync_products'  => __( 'Shopify Sync Products', 'mcp-ai-wpoos-pro' ),
            'shopify_sync_orders'    => __( 'Shopify Sync Orders', 'mcp-ai-wpoos-pro' ),
            'shopify_sync_settings'  => __( 'Shopify Sync Settings', 'mcp-ai-wpoos-pro' ),
            'shopify_sync_analytics' => __( 'Shopify Sync Analytics', 'mcp-ai-wpoos-pro' ),
        );
    }
}
```

**Toolkit Init:**
```php
// addons/pro/includes/tools/shopify-sync/init.php
function wp_mcp_ai_is_shopify_sync_toolkit_enabled() {
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    return ! empty( $settings['enable_shopify_sync_toolkit'] );
}

if ( wp_mcp_ai_is_shopify_sync_toolkit_enabled()
    && class_exists( 'WooCommerce' )
    && ! ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() )
    && class_exists( 'WP_MCP_AI_Shopify_Client' )  // Requires existing Shopify infra
) {
    // Load core sync classes.
    require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
    require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';

    // Initialize sync engine (schedules Action Scheduler hooks).
    WP_MCP_AI_Shopify_Sync_Engine::schedule_all_syncs();

    // Load admin page in admin context.
    if ( is_admin() ) {
        require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-shopify-sync-toolkit-settings-page.php';
        new WP_MCP_AI_Shopify_Sync_Toolkit_Settings_Page();
    }

    // Load webhook handler (REST endpoint registration).
    require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php';
    WP_MCP_AI_Shopify_Sync_Webhook_Handler::init();
}
```

**Acceptance Criteria:**
- [ ] Admin page appears under "Shopify Sync" menu (position 58)
- [ ] Toolkit toggle in NV oOS → Settings enables/disables the toolkit
- [ ] Admin page hidden when WooCommerce or Shopify Client not available
- [ ] Overview tab shows per-connection status cards with real data
- [ ] Configuration tab shows Shopify connection selector, interval, direction
- [ ] "Sync Now" button triggers Action Scheduler job for selected connection
- [ ] Admin page is hidden in Base Version

---

### Story 1.4 — WooCommerce Stock Writeback

**File:** Method `run_wc_sync()` in `class-wp-mcp-ai-shopify-sync-engine.php`

**Implementation:**
```php
public function run_wc_sync() {
    $settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );

    if ( empty( $settings['enable_wc_sync'] ) ) {
        return array( 'updated' => 0, 'skipped' => 0, 'direction' => 'disabled' );
    }

    $direction = $settings['sync_direction'] ?? 'shopify_to_woo';

    switch ( $direction ) {
        case 'shopify_to_woo':
            return $this->sync_shopify_to_woocommerce();
        case 'bidirectional':
            $to_woo = $this->sync_shopify_to_woocommerce();
            // Phase 2: WC→Shopify writeback placeholder.
            return $to_woo;
        default:
            return array( 'updated' => 0, 'skipped' => 0, 'direction' => 'read_only' );
    }
}

private function sync_shopify_to_woocommerce() {
    $cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $this->connection_id );
    $updated     = 0;
    $skipped     = 0;
    $page        = 1;
    $per_page    = 100;

    do {
        $items = $cct_manager->get_cached_items( array(
            'page'     => $page,
            'per_page' => $per_page,
        ));

        if ( empty( $items ) ) {
            break;
        }

        foreach ( $items as $item ) {
            $sku = $item['sku'] ?? '';
            if ( empty( $sku ) ) {
                $skipped++;
                continue;
            }

            $product_id = wc_get_product_id_by_sku( $sku );
            if ( ! $product_id ) {
                $skipped++; // No matching WC product.
                continue;
            }

            $quantity = absint( $item['available_qty'] ?? 0 );

            // HPOS-compatible stock update.
            wc_update_product_stock( $product_id, $quantity, 'set' );

            // Link CCT row to WooCommerce product for audit trail.
            if ( ! empty( $item['_ID'] ) ) {
                $cct_manager->update_woo_product_id( absint( $item['_ID'] ), $product_id );
            }

            $updated++;
        }

        $page++;
    } while ( count( $items ) >= $per_page );

    do_action( 'wp_mcp_ai_shopify_after_wc_sync', array(
        'connection_id' => $this->connection_id,
        'updated'       => $updated,
        'skipped'       => $skipped,
        'direction'     => 'shopify_to_woo',
    ));

    return array( 'updated' => $updated, 'skipped' => $skipped, 'direction' => 'shopify_to_woo' );
}
```

**Acceptance Criteria:**
- [ ] WC sync is gated behind `enable_wc_sync` setting
- [ ] Shopify→WC direction updates WooCommerce stock quantities via `wc_update_product_stock()`
- [ ] WooCommerce product ID stored back to CCT row for audit
- [ ] No WC write happens when direction is `read_only`
- [ ] HPOS compatible — no direct `post_meta` writes
- [ ] `wp_mcp_ai_shopify_after_wc_sync` hook fires with result data

---

## Phase 2: Core Tools (Week 1.5 — Stories 5–9)

**Goal:** All 5 AI-callable tools are implemented and registered. LLM can query Shopify inventory, products, and orders through the CCT cache with zero API cost.

### Story 2.1 — Connection Resolver Trait

**File:** `addons/pro/includes/tools/shopify-sync/trait-wp-mcp-ai-shopify-sync-connection-resolver.php`

**Implementation:**
```php
trait WP_MCP_AI_Shopify_Sync_Connection_Resolver {
    use WP_MCP_AI_Shopify_Connection_Resolver; // Inherits existing auto-resolution

    protected function get_shopify_sync_cct_manager( $connection_id ) {
        if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
            require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
        }
        return new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
    }

    protected function is_shopify_sync_configured( $connection_id ) {
        $settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
        $sync_connections = $settings['sync_connections'] ?? array();
        return in_array( $connection_id, $sync_connections, true );
    }

    protected function check_shopify_sync_dependencies( $connection_id ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return new WP_Error( 'wp_mcp_ai_shopify_sync_no_wc',
                __( 'WooCommerce is required for Shopify Sync.', 'mcp-ai-wpoos-pro' ) );
        }

        if ( ! $this->is_shopify_sync_configured( $connection_id ) ) {
            return new WP_Error( 'wp_mcp_ai_shopify_sync_not_configured',
                __( 'This Shopify connection is not configured for sync.', 'mcp-ai-wpoos-pro' ) );
        }

        return true;
    }
}
```

**Acceptance Criteria:**
- [ ] Extends existing `WP_MCP_AI_Shopify_Connection_Resolver` trait
- [ ] Auto-resolves connection ID from assistant context (inherited behavior)
- [ ] Checks if connection is in `sync_connections` allowlist
- [ ] Returns clear `WP_Error` when WooCommerce or sync config is missing

---

### Story 2.2 — `shopify_sync_inventory` Tool

**File:** `addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-inventory.php`

**Actions:**
| Action | Description | CCT-First? | GraphQL Cost |
|---|---|---|---|
| `search` | Search/filter inventory across locations | ✅ CCT | 0 pts |
| `get_item` | Single item by SKU or variant GID | ✅ CCT | 0 pts |
| `get_levels` | Stock levels per variant across all locations | ✅ CCT grouped query | 0 pts |
| `list_locations` | All locations with item counts | ✅ CCT DISTINCT query | 0 pts |
| `list_low_stock` | Items below reorder threshold | ✅ CCT numeric filter | 0 pts |
| `refresh` | Force full sync (Bulk Op) then return | ❌ Triggers sync | 10 pts |

**Parameters Schema:**
```php
'action'        => ['type' => 'string', 'enum' => ['search','get_item','get_levels','list_locations','list_low_stock','refresh']],
'connection_id' => ['type' => 'string', 'description' => 'Shopify connection ID. Auto-resolved if omitted.'],
'sku'           => ['type' => 'string'],
'variant_id'    => ['type' => 'string', 'description' => 'Shopify Variant GID'],
'product_type'  => ['type' => 'string'],
'vendor'        => ['type' => 'string'],
'location_id'   => ['type' => 'string'],
'stock_status'  => ['type' => 'string', 'enum' => ['in_stock','low_stock','out_of_stock']],
'search'        => ['type' => 'string', 'description' => 'Search product_title and sku'],
'orderby'       => ['type' => 'string', 'enum' => ['product_title','available_qty','price','last_synced_at','vendor']],
'order'         => ['type' => 'string', 'enum' => ['asc','desc'], 'default' => 'asc'],
'page'          => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
'per_page'      => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
```

**Acceptance Criteria:**
- [ ] All read actions return results from CCT in < 500ms (zero API cost)
- [ ] `search` supports combined filters (vendor + product_type + stock_status + text search)
- [ ] `get_levels` returns stock across all Shopify locations for a variant
- [ ] `list_low_stock` respects the configured threshold
- [ ] `refresh` triggers full sync, waits, then returns fresh data (costs 10 pts)
- [ ] `refresh` is rate-limited: max 1 per 5 minutes per connection (prevents cost abuse)
- [ ] Canonical envelope: `{success, message, data}` with Gate 2 escaping

---

### Story 2.3 — `shopify_sync_products` Tool

**File:** `addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-products.php`

**Actions:** `search`, `get_product`, `get_by_sku`, `list_by_type`, `list_by_vendor`, `list_by_status`

**Acceptance Criteria:**
- [ ] `search` returns products with image_url, price, vendor, product_type
- [ ] `get_by_sku` returns full product detail including all location inventories
- [ ] `list_by_type` / `list_by_vendor` return distinct groupings with counts
- [ ] `list_by_status` filters by ACTIVE/DRAFT/ARCHIVED
- [ ] All from CCT; zero API cost for read actions

---

### Story 2.4 — `shopify_sync_orders` Tool

**File:** `addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-orders.php`

**Actions:** `search`, `get_order`, `list_recent`, `get_order_analytics`

**Design Note:** Orders are stored with headers only in CCT (ID, status, total, customer name, date). Full order detail (line items, fulfillment, refunds) requires a live API call. This avoids caching PII-sensitive data unnecessarily.

**Acceptance Criteria:**
- [ ] `search` returns order headers from CCT (fast, zero cost)
- [ ] `get_order` hits live API for full detail (costs ~15 pts) — explicitly warns in response
- [ ] `list_recent` returns last N orders sorted by `createdAt` descending
- [ ] `get_order_analytics` returns summary stats (total orders, revenue, fulfillment rate) from CCT
- [ ] PII fields (customer email, phone, address) are redacted for `edit_posts` users; visible for `manage_woocommerce`

---

### Story 2.5 — `shopify_sync_settings` Tool

**File:** `addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-settings.php`

**Actions:** `get_settings`, `update_settings`, `get_sync_status`, `get_cost_report`, `sync_now`

**Capability:** `manage_options`

**Acceptance Criteria:**
- [ ] `get_settings` returns all toolkit configuration (interval, direction, connections, thresholds)
- [ ] `update_settings` persists changes with validation (interval must be 5/15/30/60)
- [ ] `get_sync_status` returns per-connection: last_sync, next_sync, row_count, freshness, last_error
- [ ] `get_cost_report` returns today's GraphQL points used, remaining, estimated time to refill
- [ ] `sync_now` triggers immediate full sync for specified connection; returns result

---

### Story 2.6 — `shopify_sync_analytics` Tool

**File:** `addons/pro/includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-analytics.php`

**Actions:** `inventory_summary`, `stock_velocity`, `product_performance`, `cross_store_comparison`

**All from CCT aggregates — zero API cost.**

**Acceptance Criteria:**
- [ ] `inventory_summary` returns total items, total value, items per location, items per vendor
- [ ] `stock_velocity` identifies fast/slow movers based on sync_changes over time (Phase 2: integrate with order data)
- [ ] `product_performance` shows top products by available quantity and price tier
- [ ] `cross_store_comparison` compares metrics across multiple Shopify connections

---

## Phase 3: Webhooks (Week 1 — Stories 10–12)

**Goal:** Real-time Shopify webhooks for zero-cost inventory deltas. REST endpoint, HMAC verification, topic routing are functional.

### Story 3.1 — Webhook REST Endpoint

**File:** `addons/pro/includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php`

**Implementation:**
```php
class WP_MCP_AI_Shopify_Sync_Webhook_Handler {
    const REST_NAMESPACE = 'mcp-ai/v1';
    const REST_ROUTE     = '/shopify/webhook';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
    }

    public static function register_route() {
        register_rest_route( self::REST_NAMESPACE, self::REST_ROUTE, array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_webhook' ),
            'permission_callback' => '__return_true', // HMAC verification handles auth.
        ));
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $hmac_header = $request->get_header( 'x-shopify-hmac-sha256' );
        $topic       = $request->get_header( 'x-shopify-topic' );
        $shop_domain = $request->get_header( 'x-shopify-shop-domain' );
        $body        = $request->get_body();

        // Step 1: Identify which connection this webhook belongs to.
        $connection_id = self::find_connection_by_domain( $shop_domain );
        if ( ! $connection_id ) {
            return new WP_Error( 'unknown_shop', 'Shop not recognized.', array( 'status' => 404 ) );
        }

        // Step 2: HMAC verification.
        if ( ! self::verify_hmac( $body, $hmac_header, $connection_id ) ) {
            return new WP_Error( 'hmac_mismatch', 'HMAC verification failed.', array( 'status' => 401 ) );
        }

        // Step 3: Topic routing.
        $payload = json_decode( $body, true );
        $result  = self::route_topic( $topic, $payload, $connection_id );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
        }

        return new WP_REST_Response( array( 'status' => 'processed', 'topic' => $topic ), 200 );
    }

    protected static function find_connection_by_domain( $shop_domain ) {
        // Match shop_domain to Remote Sites connections with matching URL.
        $all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
        foreach ( $all_connections as $conn ) {
            if ( 'shopify' !== ( $conn['connection_type'] ?? '' ) ) continue;
            $conn_domain = parse_url( $conn['url'] ?? '', PHP_URL_HOST );
            if ( $conn_domain === $shop_domain ) {
                return $conn['id'];
            }
        }
        return null;
    }

    protected static function verify_hmac( $body, $hmac_header, $connection_id ) {
        $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
        // For webhook HMAC, use the client_secret of the connection.
        $secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] ?? '' );
        $computed = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
        return hash_equals( $computed, $hmac_header );
    }

    protected static function route_topic( $topic, $payload, $connection_id ) {
        switch ( $topic ) {
            case 'products/update':
                return self::handle_product_update( $payload, $connection_id );
            case 'products/delete':
                return self::handle_product_delete( $payload, $connection_id );
            case 'inventory_levels/update':
                return self::handle_inventory_update( $payload, $connection_id );
            default:
                return new WP_Error( 'unknown_topic', "Unhandled webhook topic: $topic" );
        }
    }

    protected static function handle_product_update( $payload, $connection_id ) {
        $product_gid = $payload['id'] ?? '';
        if ( empty( $product_gid ) ) {
            return new WP_Error( 'missing_id', 'Product GID missing in webhook payload.' );
        }

        $client      = new WP_MCP_AI_Shopify_Client( $connection_id );
        $cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );

        return $cct_manager->sync_single_product( $product_gid );
    }

    protected static function handle_product_delete( $payload, $connection_id ) {
        $product_gid = $payload['id'] ?? '';
        $cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
        $cct_manager->mark_deleted_by_product_gid( $product_gid );
        return true;
    }

    protected static function handle_inventory_update( $payload, $connection_id ) {
        $inventory_item_id = $payload['inventory_item_id'] ?? '';
        $location_id       = $payload['location_id'] ?? '';
        $available         = $payload['available'] ?? null;

        $cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );

        return $cct_manager->update_inventory_delta( $inventory_item_id, $location_id, $available );
    }
}
```

**Tests:** `tests/test-shopify-sync-webhook-handler.php`
- Test HMAC verification (valid and invalid signatures)
- Test topic routing (products/update, products/delete, inventory_levels/update)
- Test domain-to-connection resolution
- Test 404 for unknown shop domain
- Test 401 for HMAC mismatch
- Test 500 for processing errors (Shopify will retry)

**Acceptance Criteria:**
- [ ] REST endpoint registered at `POST /wp-json/mcp-ai/v1/shopify/webhook`
- [ ] HMAC verification correctly validates Shopify signatures
- [ ] Products/update webhook triggers single-product sync (costs ~15 pts)
- [ ] Products/delete webhook marks items as `sync_status = deleted` in CCT
- [ ] Inventory_levels/update webhook updates CCT row quantity (zero API cost)
- [ ] Unknown shop domain returns 404
- [ ] HMAC mismatch returns 401
- [ ] Processing completed within 2 seconds (Shopify's 5-second timeout)

---

### Story 3.2 — Webhook Registration (per connection)

**File:** Method in `class-wp-mcp-ai-shopify-sync-engine.php` or new helper class.

**Implementation:**
```php
class WP_MCP_AI_Shopify_Sync_Webhook_Registrar {
    public static function register_webhooks( $connection_id ) {
        $client = new WP_MCP_AI_Shopify_Client( $connection_id );
        $webhook_url = rest_url( 'mcp-ai/v1/shopify/webhook' );

        $topics = array( 'products/update', 'products/delete', 'inventory_levels/update' );

        foreach ( $topics as $topic ) {
            $mutation = '
                mutation CreateWebhook($topic: WebhookSubscriptionTopic!, $callbackUrl: URL!) {
                    webhookSubscriptionCreate(topic: $topic, webhookSubscription: {callbackUrl: $callbackUrl, format: JSON}) {
                        webhookSubscription { id }
                        userErrors { field message }
                    }
                }';

            $result = $client->graphql( $mutation, array(
                'topic'       => $topic,
                'callbackUrl' => $webhook_url,
            ));
            // Log registration result.
        }

        update_option( "wp_mcp_ai_shopify_webhook_registered_{$connection_id}", true );
    }

    public static function unregister_webhooks( $connection_id ) {
        // List all webhooks, delete matching ones.
        $client = new WP_MCP_AI_Shopify_Client( $connection_id );

        $query = 'query { webhookSubscriptions(first: 50) { edges { node { id endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } } } } } }';
        $result = $client->graphql( $query );
        // Filter by callbackUrl matching rest_url(...) and delete.
    }
}
```

**Acceptance Criteria:**
- [ ] Webhooks are registered via Shopify Admin GraphQL mutation
- [ ] Registration status is tracked per connection in options
- [ ] Admin UI shows "Register" / "Unregister" buttons per connection
- [ ] Webhook URL displayed in admin for manual Shopify admin setup
- [ ] Deactivation unregisters all webhooks

---

### Story 3.3 — Webhook Admin UI & Status

**File:** `render_webhooks_tab()` method in admin settings page.

**Acceptance Criteria:**
- [ ] Webhook tab shows per-connection registration status (✅ Registered / ❌ Not Registered)
- [ ] Shows webhook topics, delivery URL, last delivery attempt
- [ ] "Test HMAC" button: computes a valid HMAC from a test payload and sends to the endpoint
- [ ] "Copy Webhook URL" button
- [ ] Instructions for manual Shopify admin webhook setup (as alternative to auto-registration)

---

## Phase 4: Testing & Documentation (Week 1 — Stories 13–17)

**Goal:** Full test coverage, documentation, CI readiness, cost analytics dashboard.

### Story 4.1 — Unit Tests

**Files:**
- `tests/test-shopify-sync-cct-manager.php`
- `tests/test-shopify-sync-engine.php`
- `tests/test-shopify-sync-tools.php`
- `tests/test-shopify-sync-webhook-handler.php`

**Test Categories:**

| Test File | Coverage |
|---|---|
| **CCT Manager** | Column creation, upsert, bulk upsert from JSONL, hash skip, filter queries, freshness, truncation, WooCommerce product ID linking |
| **Sync Engine** | Bulk operation scheduling, full sync flow, WC sync flow, cost tracking, multi-connection isolation, deactivation cleanup |
| **Tools** | Capability gates (admin/manager/subscriber/guest), argument sanitization (Gate 1), output escaping (Gate 2), canonical envelope shape, CCT read path, refresh rate limiting, cost warning on low budget |
| **Webhooks** | HMAC verification, topic routing, domain resolution, error responses (401, 404, 500), product update/delete/inventory update flows |
| **Security** | Capability checks per action, credential encryption, HMAC verification, PII redaction in orders tool |

**PHPUnit Configuration:**
```xml
<phpunit>
    <testsuites>
        <testsuite name="Shopify Sync">
            <directory>tests/test-shopify-sync-*.php</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Acceptance Criteria:**
- [ ] > 80% line coverage on CCT manager, sync engine, tools, webhook handler
- [ ] All capability gates tested (admin can sync_now, subscriber gets 403)
- [ ] All guest access paths tested (guests get proper 403 for write actions)
- [ ] All `WP_Error` return paths tested
- [ ] All canonical envelope success paths tested
- [ ] Two-gate sanitisation verified (no raw `$arguments` usage in output)
- [ ] HMAC verification tested with known-valid and tampered signatures

---

### Story 4.2 — Tool Reference Documentation

**File:** Add Shopify Sync section to `docs/reference/tools/tool-reference.md`

**Content per tool:**
- Slug, name, description
- Parameter table (name, type, description, required, default, enum, CCT-first indicator)
- Capability requirements
- Example usage (natural language prompts)
- Return shape (canonical envelope)
- GraphQL cost implications (0 pts read / 10 pts refresh / 15 pts order detail)

---

### Story 4.3 — Toolkit README

**File:** `addons/pro/includes/tools/shopify-sync/README.md`

Follow the established format from `addons/pro/includes/tools/flowhub/README.md`:
- Purpose
- Tool Inventory table
- Architecture diagram
- Dependencies (WooCommerce, JetEngine optional, WP_MCP_AI_Shopify_Client, Remote Sites)
- Registration gates
- Key Files table
- Admin Page description
- See Also links

---

### Story 4.4 — User-Facing Documentation

**File:** `docs/toolkits/shopify-sync-integration.md`

**Content:**
- What is Shopify Sync?
- Prerequisites (WooCommerce, Shopify store, Remote Sites connection, JetEngine)
- Installation & activation (toolkit toggle)
- Configuration walkthrough (connections, interval, direction)
- Understanding GraphQL costs and the cost dashboard
- Webhook setup (auto + manual)
- Tool usage examples with natural language prompts
- Troubleshooting common issues (HMAC failures, webhook timeouts, cost exhaustion)
- Comparison: When to use Sync tools vs. Live API tools

---

### Story 4.5 — GraphQL Cost Analytics Dashboard

**File:** Admin page tab or dashboard widget.

**Acceptance Criteria:**
- [ ] "Today's Cost" gauge showing points used / 1,000
- [ ] Per-connection breakdown: bulk ops, webhooks, manual queries
- [ ] 7-day cost history chart (bar chart: daily points used)
- [ ] Cost projection: "At current rate, budget lasts X hours"
- [ ] Warning banner when < 20% budget remaining
- [ ] WP-CLI: `wp shopify-sync cost-report` prints summary table

---

## Phase 5: Polish & WP-CLI (Week 0.5 — Stories 18–21)

**Goal:** WP-CLI commands, error notification system, final review, and PR readiness.

### Story 5.1 — WP-CLI Commands

**File:** Register commands in `class-wp-mcp-ai-shopify-sync-engine.php` or separate CLI file.

**Commands:**
```bash
wp shopify-sync status [--connection=<id>]           # Show sync status
wp shopify-sync trigger <connection_id>               # Force full sync
wp shopify-sync clear-cache <connection_id> [--force] # Truncate CCT
wp shopify-sync register-webhooks <connection_id>     # Register Shopify webhooks
wp shopify-sync unregister-webhooks <connection_id>   # Unregister webhooks
wp shopify-sync cost-report [--connection=<id>] [--days=7]  # Cost report
wp shopify-sync list-connections                      # List sync-enabled connections
```

**Acceptance Criteria:**
- [ ] All commands are registered via WP-CLI
- [ ] `wp shopify-sync status` shows per-connection table with all relevant fields
- [ ] `wp shopify-sync trigger` returns sync result (items, duration, errors)
- [ ] `wp shopify-sync clear-cache` requires `--force` flag with confirmation prompt
- [ ] `wp shopify-sync cost-report` shows formatted table with 7-day history

---

### Story 5.2 — Error Notification System

**Implementation:** Extends the FlowHub pattern (PR #5501 Story 3.3) for Shopify context:
```php
public static function handle_sync_error( $error, $connection_id ) {
    $message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

    // Store per-connection error.
    update_option( "wp_mcp_ai_shopify_last_sync_error_{$connection_id}", $message );

    // Log to plugin logger with connection context.
    if ( function_exists( 'wp_mcp_ai_log' ) ) {
        wp_mcp_ai_log(
            sprintf( 'Shopify sync error [%s]: %s', $connection_id, $message ),
            'error'
        );
    }

    // Email admin with connection details.
    $connection   = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    $store_name   = $connection['name'] ?? $connection_id;
    $admin_email  = get_option( 'admin_email' );
    $subject      = sprintf( '[%s] Shopify Sync Error — %s', get_bloginfo( 'name' ), $store_name );

    wp_mail( $admin_email, $subject, sprintf(
        "A Shopify sync error occurred.\n\nStore: %s\nConnection: %s\nError: %s\nTime: %s\n\nCheck Shopify Sync Toolkit settings to diagnose.",
        $store_name,
        $connection_id,
        $message,
        current_time( 'mysql' )
    ));

    // Admin notice (shown on next admin page load).
    add_action( 'admin_notices', array( __CLASS__, 'show_sync_error_notice' ) );
}
```

**Acceptance Criteria:**
- [ ] Sync errors trigger admin email with store name and connection ID
- [ ] Dismissible admin notice appears on dashboard and Shopify Sync admin page
- [ ] Error is logged to WP_MCP_AI activity log with connection context
- [ ] Notice includes link to Shopify Sync settings
- [ ] Per-connection: errors don't block other connections from syncing
- [ ] Error is cleared after successful sync

---

### Story 5.3 — Cost Budget Safeguard

**Implementation:** Sync engine checks cost budget before each sync:
```php
public function should_skip_sync_due_to_cost( $connection_id ) {
    $cost_data = get_option( "wp_mcp_ai_shopify_daily_cost_{$connection_id}", array(
        'used'      => 0,
        'limit'     => 1000,
        'refill_at' => 0,
    ));

    $pct_remaining = ( ( $cost_data['limit'] - $cost_data['used'] ) / $cost_data['limit'] ) * 100;

    if ( $pct_remaining < 10 ) {
        // Skip this sync cycle; try next one.
        wp_mcp_ai_log( "Shopify sync skipped for {$connection_id}: cost budget too low ({$pct_remaining}%).", 'warning' );
        return true;
    }

    return false;
}
```

**Acceptance Criteria:**
- [ ] Sync is skipped when < 10% of daily cost budget remains
- [ ] Skipped syncs are logged with `warning` level
- [ ] Admin notice warns when budget is critically low
- [ ] AI tool `refresh` action is also rate-limited by cost budget

---

### Story 5.4 — Final Review & PR

**Checklist:**
- [ ] All 21 stories pass acceptance criteria
- [ ] Full test suite passes (`vendor/bin/phpunit tests/test-shopify-sync-*.php`)
- [ ] PHPCS lint passes (`composer run lint`)
- [ ] PHP compatibility check passes (`composer run lint:compat`)
- [ ] Code review by agent (checking two-gate sanitisation, canonical envelope, capability gates)
- [ ] No changes to `class-wp-mcp-ai-shopify-client.php` — consumed as-is
- [ ] Tool reference documentation updated
- [ ] Toolkit README added
- [ ] User-facing documentation added
- [ ] Admin page accessible and functional
- [ ] WP-CLI commands tested end-to-end
- [ ] Webhook HMAC verified with real Shopify test store
- [ ] Changelog entry added
- [ ] Pro README updated (toolkit listing)

---

## Appendix A: Option Key Reference

| Option Key | Type | Default | Purpose |
|---|---|---|---|
| `wp_mcp_ai_settings[enable_shopify_sync_toolkit]` | bool | `false` | Master toolkit toggle |
| `wp_mcp_ai_shopify_sync_toolkit_settings[sync_interval]` | int | `15` | Minutes between syncs (5/15/30/60) |
| `wp_mcp_ai_shopify_sync_toolkit_settings[sync_direction]` | string | `'shopify_to_woo'` | `shopify_to_woo` / `bidirectional` / `read_only` |
| `wp_mcp_ai_shopify_sync_toolkit_settings[enable_wc_sync]` | bool | `false` | Enable WC stock writeback |
| `wp_mcp_ai_shopify_sync_toolkit_settings[enable_webhooks]` | bool | `true` | Enable webhook registration |
| `wp_mcp_ai_shopify_sync_toolkit_settings[cct_slug]` | string | `'shopify_inventory_sync'` | JetEngine CCT slug |
| `wp_mcp_ai_shopify_sync_toolkit_settings[low_stock_threshold]` | int | `5` | "Low Stock" badge threshold |
| `wp_mcp_ai_shopify_sync_toolkit_settings[field_mapping]` | array | `[]` | Custom field mapping overrides |
| `wp_mcp_ai_shopify_sync_toolkit_settings[sync_connections]` | array | `[]` | Connection IDs to sync |
| `wp_mcp_ai_shopify_last_sync_{conn_id}` | string | `''` | ISO 8601 timestamp (per connection) |
| `wp_mcp_ai_shopify_last_sync_error_{conn_id}` | string | `''` | Transient error for admin notice (per connection) |
| `wp_mcp_ai_shopify_daily_cost_{conn_id}` | object | `{}` | `{used, limit, refill_at, history}` |
| `wp_mcp_ai_shopify_webhook_registered_{conn_id}` | bool | `false` | Webhook registration status |
| `wp_mcp_ai_shopify_sync_db_version` | string | `'1.0'` | Schema version tracker |

## Appendix B: File Manifest

```
New files (~18):
├── addons/pro/includes/
│   ├── class-wp-mcp-ai-shopify-sync-cct-manager.php          # CCT cache management
│   ├── class-wp-mcp-ai-shopify-sync-engine.php               # Action Scheduler + cost tracking
│   ├── class-wp-mcp-ai-shopify-sync-webhook-handler.php      # REST endpoint + HMAC
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
    ├── test-shopify-sync-tools.php
    └── test-shopify-sync-webhook-handler.php

Modified files (2):
├── addons/pro/mcp-ai-wpoos-pro.php              ← wp_mcp_ai_pro_register_tools() + init loading
└── docs/reference/tools/tool-reference.md        ← Tool entries

New docs (2):
├── docs/project/proposals/
│   ├── SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md
│   └── SHOPIFY-SYNC-IMPLEMENTATION-PLAN.md       # This document
└── docs/toolkits/shopify-sync-integration.md

NOT modified (consumed as-is):
└── addons/pro/includes/class-wp-mcp-ai-shopify-client.php  # Existing 1,390-line client
```

## Appendix C: Deactivation & Uninstall Behavior

| Event | What Happens |
|---|---|
| **Toolkit toggle OFF** | Tools unregistered. Admin page hidden. Sync continues (scheduled jobs are WordPress-level). Options preserved. |
| **Plugin deactivated** | Action Scheduler hooks cleared for all connections. Webhooks unregistered. Options preserved. CCT data preserved. |
| **Plugin uninstalled** | All `wp_mcp_ai_shopify_sync_*` options removed. CCT table is JetEngine's — not touched. Scheduled actions cleared. Webhooks unregistered. |

Following the `wp-plugin-lifecycle` skill: **never delete user data on deactivation**. CCT data belongs to JetEngine and persists. Shopify store data is not modified by uninstall.

## Appendix D: Differences from the FlowHub Pro Toolkit (PR #5501)

| Component | FlowHub Toolkit | Shopify Sync Toolkit |
|---|---|---|
| **API Client** | New `WP_MCP_AI_FlowHub_Client` (REST) | Reuses existing `WP_MCP_AI_Shopify_Client` (GraphQL) — no changes |
| **Sync Strategy** | Polling only (REST offset/limit) | Bulk Operations (10 pts) + webhooks (0 pts) + GraphQL delta |
| **Cost Model** | Free API | GraphQL cost budget management + daily tracking |
| **Scheduling** | Single Action Scheduler hook | Per-connection hooks + multi-connection isolation |
| **Change Detection** | `sync_hash` comparison | `sync_hash` + `updated_at` timestamp delta |
| **Webhooks** | N/A | Full HMAC-verified REST endpoint |
| **Real-time** | Configurable interval only | Webhooks (near-instant) + polling (safety net) |
| **WC Integration** | `wc_update_product_stock()` | Same + `wc_get_product_id_by_sku()` + HPOS compatibility |
| **Migration** | Detects standalone plugin | No migration needed — new standalone feature |
| **WP-CLI** | N/A | `wp shopify-sync` with 7 commands |
| **Multi-store** | Single FlowHub account | Multiple Shopify connections, each independently configurable |
| **Admin UI** | Top-level menu (57) | Top-level menu (58) with 4 tabs including webhook status |
| **Cost Analytics** | N/A | Per-connection daily budget gauge + 7-day history |
| **CCT Columns** | 25 cannabis-specific fields | 27 commerce-generic fields |

---

## Appendix E: GraphQL Cost Budget Reference

| Operation | Cost | Notes |
|---|---|---|
| **Bulk Operation** (all products) | **10 pts** | Flat cost regardless of catalog size. Includes JSONL download. |
| **Bulk Operation** (all orders) | **10 pts** | Same flat cost. Recommended for order header caching. |
| **Single product query** (with variants, images, inventory) | ~15–50 pts | Used for webhook-driven single-product syncs. |
| **List 250 products** (with variants) | ~150–300 pts | Used for incremental delta syncs. Avoid for full syncs. |
| **Inventory levels** (250 items at 1 location) | ~50–100 pts | Used for location-specific deltas. |
| **Webhook delivery** | **0 pts** | Preferred path. Process immediately, update CCT. |
| **Bucket refill** | 50 pts/sec | ~1,000 pts every 20 seconds. |
| **Daily budget** | 1,000 points | Shared across all GraphQL operations for the store. |

**Sync strategy selection logic:**
```
if webhooks are active and webhook received:
    → Process webhook (0 pts)
elif daily_cost_used < 800 pts:  // 80% budget remaining
    → Run full sync via Bulk Operation (10 pts)
elif daily_cost_used < 950 pts:  // 5% budget remaining
    → Run delta sync (GraphQL query, ~150 pts)
else:  // Budget critically low
    → Skip sync, log warning, alert admin
```
