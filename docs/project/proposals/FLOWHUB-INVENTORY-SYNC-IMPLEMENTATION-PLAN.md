# FlowHub Inventory Sync — Pro Toolkit Implementation Plan

**Date:** June 28, 2026  
**Status:** 📋 PENDING APPROVAL  
**Related:** [FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md](./FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md)  
**Target Release:** NV oOS Pro v1.2.0  
**Estimated Duration:** 5 weeks (19 stories)

---

## Executive Summary

This document provides a phased, story-level implementation plan for the FlowHub Inventory Sync Pro Toolkit. Each phase is self-contained and testable. Phases 1–3 produce a working toolkit; Phases 4–5 harden, document, and provide migration.

---

## Pre-Implementation Checklist

- [ ] Obtain read access to `flowhub-inventory-sync` private repo
- [ ] Review source plugin in full (especially `flowhub-inventory-sync.php` sync engine, `class-fis-schema.php`, `class-fis-inventory-presenter.php`)
- [ ] Verify FlowHub API v0 endpoint contracts (GET `/v0/inventoryNonZero`)
- [ ] Confirm JetEngine CCT column auto-creation API (`jet_engine()->cct->add_field()`)
- [ ] Confirm Action Scheduler availability (bundled with WooCommerce)
- [ ] Identify test FlowHub API credentials for development
- [ ] Set up local WooCommerce + JetEngine + NV oOS Pro development environment

---

## Phase 1: Foundation (Week 1 — Stories 1–3)

**Goal:** API client, CCT manager, admin page skeleton, and toolkit toggle are functional. No tools yet.

### Story 1.1 — FlowHub API Client

**File:** `addons/pro/includes/class-wp-mcp-ai-flowhub-client.php`

**Implementation:**
```php
class WP_MCP_AI_FlowHub_Client {
    const API_BASE_URL = 'https://api.flowhub.co/v0/';
    const DEFAULT_TIMEOUT = 30;
    const MAX_RESPONSE_SIZE = 5242880;
    const MAX_RETRIES = 3;

    protected $api_key;
    protected $client_id;
    protected $base_url;
    protected $timeout;

    public function __construct( $client_id = null, $api_key = null, $base_url = null );

    // Core API methods:
    public function get_inventory( $params = array() );        // GET /inventoryNonZero
    public function get_inventory_by_page( $page, $per_page = 100 );
    public function get_all_inventory( callable $progress = null ); // Paginated full pull
    public function get_product( $product_id );
    public function get_locations();
    public function check_connection();                         // Lightweight health check
    public function get_last_error();
    public function get_last_response_code();
}
```

**Key patterns:**
- Follow `class-wp-mcp-ai-shopify-client.php` structure
- Use `wp_remote_get()` with `X-Api-Client-Id` and `X-Api-Key` headers
- Rate limit: 5 requests/second max, enforced via `usleep()` between pages
- Response size check: abort if body exceeds `MAX_RESPONSE_SIZE`
- Store `last_error` and `last_response_code` for admin diagnostics
- All API calls wrapped in `try/catch`; return `WP_Error` on network/timeout failures

**Tests:** `tests/test-flowhub-client.php`
- Mock `wp_remote_get` responses
- Test successful inventory pull
- Test API error responses (401, 429, 500, timeout)
- Test pagination logic
- Test connection health check
- Test rate limiting

**Acceptance Criteria:**
- [ ] Client successfully authenticates and retrieves inventory from FlowHub API
- [ ] Paginated full pull works (test with 500+ items across 5 pages)
- [ ] Connection check returns valid status
- [ ] Errors are properly surfaced as `WP_Error`
- [ ] Tests pass with mocked HTTP responses

---

### Story 1.2 — CCT Manager

**File:** `addons/pro/includes/class-wp-mcp-ai-flowhub-cct-manager.php`

**Implementation:**
```php
class WP_MCP_AI_FlowHub_CCT_Manager {
    const CCT_SLUG_DEFAULT = 'flowhub_inventory';

    protected $cct_slug;
    protected $client;

    public function __construct( WP_MCP_AI_FlowHub_Client $client = null );

    // Schema management:
    public function get_cct_slug();
    public function set_cct_slug( $slug );
    public function ensure_columns();               // Auto-create missing CCT columns
    public function get_column_definitions();        // Returns the columns array
    public function is_cct_available();              // JetEngine + CCT exists check

    // Read operations (CCT queries, no API calls):
    public function get_cached_items( $filters = array() );   // Search/filter/sort
    public function get_cached_item( $identifier, $by = 'sku' );
    public function get_cached_item_by_product_id( $product_id, $location_id = null );
    public function get_row_count();
    public function get_distinct_values( $column );         // For filter dropdowns
    public function get_last_sync_time();
    public function is_fresh( $max_age_seconds = 900 );     // 15 min default

    // Write operations:
    public function upsert( array $flowhub_item, array $mapping );
    public function truncate();
    public function mark_stale();
    public function delete_item( $cct_item_id );

    // Sync orchestration (delegates to client):
    public function sync_from_api( $force = false, callable $progress = null );
    public function sync_single_product( $product_id );
}
```

**CCT Query Implementation:**
```php
public function get_cached_items( $filters = array() ) {
    // Use JetEngine CCT query API
    $args = array(
        'post_type'  => $this->cct_slug,
        'number'     => min( absint( $filters['per_page'] ?? 50 ), 100 ),
        'paged'      => absint( $filters['page'] ?? 1 ),
        'order'      => sanitize_key( $filters['order'] ?? 'DESC' ),
        'orderby'    => sanitize_key( $filters['orderby'] ?? 'last_updated' ),
        'meta_query' => array(),
    );

    // Build meta_query from filters
    if ( ! empty( $filters['category'] ) ) {
        $args['meta_query'][] = array(
            'key'   => 'category',
            'value' => sanitize_text_field( $filters['category'] ),
        );
    }
    // ... additional filters ...
    // Search across product_name and sku
    if ( ! empty( $filters['search'] ) ) {
        $args['s'] = sanitize_text_field( $filters['search'] );
    }

    return jet_engine()->cct->data->get_items( $args );
}
```

**Tests:** `tests/test-flowhub-cct-manager.php`
- Test column auto-creation (mock `jet_engine()->cct->add_field()`)
- Test upsert logic (new item, existing item update, conflict)
- Test filter queries (by category, location, stock status, search)
- Test freshness check (is_fresh with various ages)
- Test truncate and re-seed
- Test graceful behavior when JetEngine is not active

**Acceptance Criteria:**
- [ ] CCT columns are auto-created on first sync
- [ ] Upsert correctly handles new vs. existing items (matched on product_id + location_id)
- [ ] Cached queries return correct results for all filter combinations
- [ ] `is_fresh()` returns correct boolean based on `last_sync` timestamp
- [ ] Missing JetEngine returns clear `WP_Error` with actionable message

---

### Story 1.3 — Admin Page Skeleton & Toolkit Toggle

**Files:**
- `addons/pro/includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php`
- `addons/pro/includes/tools/flowhub/init.php`

**Admin Page Implementation:**
```php
class WP_MCP_AI_FlowHub_Toolkit_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {
    public function __construct() {
        $this->toolkit_slug     = 'flowhub';
        $this->toolkit_name     = __( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' );
        $this->option_name      = 'wp_mcp_ai_flowhub_toolkit_settings';
        $this->page_slug        = 'wp-mcp-ai-flowhub-toolkit-settings';
        $this->parent_slug      = 'wp-mcp-ai-flowhub-toolkit';
        $this->has_remote_sites = true;
        $this->icon             = 'dashicons-store';
        $this->has_research     = false;

        add_action( 'admin_menu', array( $this, 'add_top_level_menu' ), 25 );
        parent::__construct();
    }

    public function add_top_level_menu() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        add_menu_page(
            __( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' ),
            __( 'FlowHub Toolkit', 'mcp-ai-wpoos-pro' ),
            'manage_woocommerce',
            $this->parent_slug,
            array( $this, 'redirect_to_first_submenu' ),
            $this->icon,
            57
        );
    }

    protected function render_overview_tab() { /* Connection status, sync stats */ }
    protected function render_configuration_tab() { /* Credentials, interval, direction */ }
    protected function get_tools_list() { /* Return tool slug → name map */ }
}
```

**Toolkit Init:**
```php
// addons/pro/includes/tools/flowhub/init.php
function wp_mcp_ai_is_flowhub_toolkit_enabled() {
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    return ! empty( $settings['enable_flowhub_toolkit'] );
}

if ( wp_mcp_ai_is_flowhub_toolkit_enabled()
    && class_exists( 'WooCommerce' )
    && ! ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() )
) {
    if ( is_admin() ) {
        require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-flowhub-toolkit-settings-page.php';
        new WP_MCP_AI_FlowHub_Toolkit_Settings_Page();
    }
}
```

**Registration in `wp_mcp_ai_pro_register_tools()`:**
```php
// In addons/pro/mcp-ai-wpoos-pro.php, inside wp_mcp_ai_pro_register_tools():
if ( wp_mcp_ai_is_flowhub_toolkit_enabled() && class_exists( 'WooCommerce' ) ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
    require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
    require_once WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/trait-wp-mcp-ai-flowhub-connection-resolver.php';

    $registry->register( new WP_MCP_AI_Pro_Tool_FlowHub_Inventory() );
    $registry->register( new WP_MCP_AI_Pro_Tool_FlowHub_Products() );
    $registry->register( new WP_MCP_AI_Pro_Tool_FlowHub_Locations() );
    $registry->register( new WP_MCP_AI_Pro_Tool_FlowHub_Sync() );
    $registry->register( new WP_MCP_AI_Pro_Tool_FlowHub_Settings() );
}
```

**Acceptance Criteria:**
- [ ] Admin page appears under "FlowHub Toolkit" menu (position 57)
- [ ] Toolkit toggle in NV oOS → Settings enables/disables the toolkit
- [ ] Admin page is hidden when WooCommerce is not active
- [ ] Overview tab shows placeholder content
- [ ] Configuration tab shows API credential fields

---

## Phase 2: Core Tools (Week 1.5 — Stories 4–8)

**Goal:** All 5 tool classes are implemented and registered. LLM can query FlowHub inventory, products, and locations through the CCT cache.

### Story 2.1 — Connection Resolver Trait

**File:** `addons/pro/includes/tools/flowhub/trait-wp-mcp-ai-flowhub-connection-resolver.php`

**Implementation:**
```php
trait WP_MCP_AI_FlowHub_Connection_Resolver {
    protected function resolve_flowhub_connection( $arguments, $context ) {
        // Read credentials from settings or Remote Sites
        $settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );

        $api_key    = $settings['api_key'] ?? '';
        $client_id  = $settings['client_id'] ?? '';
        $base_url   = $settings['api_base_url'] ?? '';

        if ( empty( $api_key ) || empty( $client_id ) ) {
            return new WP_Error(
                'wp_mcp_ai_flowhub_missing_credentials',
                __( 'FlowHub API credentials are not configured.', 'mcp-ai-wpoos-pro' )
            );
        }

        return new WP_MCP_AI_FlowHub_Client( $client_id, $api_key, $base_url );
    }

    protected function get_cct_manager() {
        return new WP_MCP_AI_FlowHub_CCT_Manager();
    }
}
```

**Acceptance Criteria:**
- [ ] Resolves credentials from toolkit settings
- [ ] Returns `WP_Error` when credentials are missing
- [ ] Accepts optional connection ID for multi-store support (future)

---

### Story 2.2 — flowhub_inventory Tool

**File:** `addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-inventory.php`

**Actions:**
| Action | Description | CCT-First? |
|---|---|---|
| `search` | Search/filter inventory items | ✅ Always from CCT |
| `get_item` | Get single item by SKU or product_id | ✅ CCT first, API fallback if not found |
| `get_levels` | Get stock levels for specific product across all locations | ✅ CCT grouped query |
| `refresh` | Force re-sync from API then return results | ❌ Triggers API call |

**Parameters Schema:**
```php
'action'      => ['type' => 'string', 'enum' => ['search','get_item','get_levels','refresh']],
'sku'         => ['type' => 'string', 'description' => 'Product SKU'],
'product_id'  => ['type' => 'string', 'description' => 'FlowHub product ID'],
'category'    => ['type' => 'string', 'description' => 'Category filter (Flower, Edible, etc.)'],
'location'    => ['type' => 'string', 'description' => 'Location name or ID'],
'stock_status'=> ['type' => 'string', 'enum' => ['in_stock','low_stock','out_of_stock']],
'search'      => ['type' => 'string', 'description' => 'Full-text search across name/SKU'],
'orderby'     => ['type' => 'string', 'enum' => ['product_name','quantity','last_updated','sku']],
'order'       => ['type' => 'string', 'enum' => ['asc','desc'], 'default' => 'asc'],
'page'        => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
'per_page'    => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
```

**Acceptance Criteria:**
- [ ] `search` returns filtered, paginated results from CCT
- [ ] `get_item` by SKU returns single item or `WP_Error` if not found
- [ ] `get_levels` returns stock across all locations for a product
- [ ] `refresh` triggers sync and returns fresh data (admin only)
- [ ] All responses use canonical envelope: `{success, message, data}`
- [ ] Two-gate sanitisation enforced (sanitize arguments, escape output)

---

### Story 2.3 — flowhub_products Tool

**File:** `addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-products.php`

**Actions:** `search`, `get_product`, `get_by_sku`, `list_categories`

**Acceptance Criteria:**
- [ ] `search` returns products with optional category/location filters
- [ ] `get_by_sku` returns full product detail with image_url, price, description
- [ ] `list_categories` returns distinct categories with counts
- [ ] All from CCT; no API calls for read actions

---

### Story 2.4 — flowhub_locations Tool

**File:** `addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-locations.php`

**Actions:** `list`, `get_location`

**Acceptance Criteria:**
- [ ] `list` returns all locations (from CCT `DISTINCT location_name`)
- [ ] `get_location` returns location detail with item count
- [ ] Results include location_id, location_name, and item_count

---

### Story 2.5 — flowhub_sync Tool

**File:** `addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-sync.php`

**Actions:** `sync_now`, `sync_status`, `clear_cache`

**Capability:** `manage_options` (elevated — this changes data)

**Acceptance Criteria:**
- [ ] `sync_now` triggers full API pull → CCT upsert, returns item count
- [ ] `sync_status` returns last_sync time, row count, freshness, last error
- [ ] `clear_cache` truncates CCT (requires confirmation flag)
- [ ] Sync progress is logged to `wp_mcp_ai_recent_activity`
- [ ] Fire hook `wp_mcp_ai_flowhub_after_sync` with result data

---

### Story 2.6 — flowhub_settings Tool

**File:** `addons/pro/includes/tools/flowhub/class-wp-mcp-ai-pro-tool-flowhub-settings.php`

**Actions:** `get_settings`, `update_settings`, `test_connection`, `get_field_mapping`

**Capability:** `manage_options`

**Acceptance Criteria:**
- [ ] `get_settings` returns current toolkit configuration
- [ ] `update_settings` persists changes to `wp_mcp_ai_flowhub_toolkit_settings`
- [ ] `test_connection` calls FlowHub health endpoint, returns status
- [ ] `get_field_mapping` returns current field mapping config

---

## Phase 3: Sync Engine (Week 1 — Stories 9–12)

**Goal:** Background sync via Action Scheduler, field mapping, WooCommerce stub, and error notification are functional.

### Story 3.1 — Action Scheduler Integration

**File:** `addons/pro/includes/class-wp-mcp-ai-flowhub-sync-engine.php`

**Implementation:**
```php
class WP_MCP_AI_FlowHub_Sync_Engine {
    const HOOK_FULL_SYNC = 'wp_mcp_ai_flowhub_full_sync';
    const HOOK_WC_SYNC   = 'wp_mcp_ai_flowhub_wc_sync';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'schedule_recurring_syncs' ) );
        add_action( self::HOOK_FULL_SYNC, array( __CLASS__, 'run_full_sync' ) );
        add_action( self::HOOK_WC_SYNC, array( __CLASS__, 'run_wc_sync' ) );
    }

    public static function schedule_recurring_syncs() {
        $settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
        $interval = absint( $settings['sync_interval'] ?? 15 ) * 60;

        if ( ! as_has_scheduled_action( self::HOOK_FULL_SYNC ) ) {
            as_schedule_recurring_action(
                time(),
                $interval,
                self::HOOK_FULL_SYNC,
                array(),
                'flowhub',
                true  // unique
            );
        }

        // WC sync is optional
        if ( ! empty( $settings['enable_wc_sync'] )
            && ! as_has_scheduled_action( self::HOOK_WC_SYNC )
        ) {
            as_schedule_recurring_action(
                time(),
                $interval,
                self::HOOK_WC_SYNC,
                array(),
                'flowhub_wc',
                true
            );
        }
    }

    public static function run_full_sync() {
        $client      = self::get_client();
        $cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager( $client );
        $result      = $cct_manager->sync_from_api();

        if ( is_wp_error( $result ) ) {
            self::handle_sync_error( $result );
        } else {
            update_option( 'wp_mcp_ai_flowhub_last_sync', current_time( 'mysql' ) );
            delete_option( 'wp_mcp_ai_flowhub_last_sync_error' );
            do_action( 'wp_mcp_ai_flowhub_after_sync', $result );
        }
    }
}
```

**Deactivation Cleanup:**
```php
// In addons/pro/uninstall.php (or deactivation hook):
as_unschedule_all_actions( 'wp_mcp_ai_flowhub_full_sync', array(), 'flowhub' );
as_unschedule_all_actions( 'wp_mcp_ai_flowhub_wc_sync', array(), 'flowhub_wc' );
```

**Acceptance Criteria:**
- [ ] Action Scheduler recurring action is scheduled on settings save
- [ ] Sync runs on the configured interval
- [ ] No overlapping syncs (enforced by `unique` parameter)
- [ ] Sync errors are logged and surfaced in admin
- [ ] Deactivation cleans up all scheduled actions

---

### Story 3.2 — Field Mapping System

**File:** Included in `class-wp-mcp-ai-flowhub-cct-manager.php` (mapping methods)

**Implementation:**
```php
protected function get_default_field_mapping() {
    return array(
        'product_id'           => 'productId',
        'variant_id'           => 'variantId',
        'parent_product_id'    => 'parentProductId',
        'sku'                  => 'sku',
        'product_name'         => 'productName',
        'variant_name'         => 'variantName',
        'category'             => 'category',
        'custom_category_name' => 'customCategoryName',
        'purchase_category'    => 'purchaseCategory',
        'product_description'  => 'productDescription',
        'quantity'             => 'quantity',
        'location_id'          => 'locationId',
        'location_name'        => 'locationName',
        'unit_of_measure'      => 'inventoryUnitOfMeasure',
        'image_url'            => '_extracted.image_url',    // Special extractors
        'price'                => '_extracted.price',
    );
}

protected function map_flowhub_item_to_cct_row( $item, $mapping ) {
    $row = array();
    foreach ( $mapping as $cct_column => $fh_field ) {
        if ( strpos( $fh_field, '_extracted.' ) === 0 ) {
            // Special extractors that parse item_data JSON
            $key = substr( $fh_field, 11 );
            $row[ $cct_column ] = $this->extract_from_item_data( $item, $key );
        } else {
            $row[ $cct_column ] = $item[ $fh_field ] ?? '';
        }
    }
    $row['item_data']   = wp_json_encode( $item );
    $row['sync_hash']   = md5( wp_json_encode( $item ) );
    $row['last_updated'] = current_time( 'mysql' );
    return $row;
}
```

**Acceptance Criteria:**
- [ ] Default mapping correctly transforms FlowHub API response to CCT columns
- [ ] Custom mappings (overridden in settings) are respected
- [ ] Special extractors parse image_url and price from item_data JSON
- [ ] sync_hash is computed and stored for change detection

---

### Story 3.3 — Error Notification System

**Implementation:** Replicates `fis_notify_error()` from source plugin:
```php
public static function handle_sync_error( $error ) {
    $message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;

    // Store for admin notice
    update_option( 'wp_mcp_ai_flowhub_last_sync_error', $message );

    // Email admin
    $admin_email = get_option( 'admin_email' );
    $subject = sprintf( '[%s] FlowHub Sync Error', get_bloginfo( 'name' ) );
    wp_mail( $admin_email, $subject, $message );

    // Log to plugin logger
    if ( function_exists( 'wp_mcp_ai_log' ) ) {
        wp_mcp_ai_log( 'FlowHub sync error: ' . $message, 'error' );
    }

    // Admin notice (shown on next admin page load)
    add_action( 'admin_notices', array( __CLASS__, 'show_sync_error_notice' ) );
}

public static function show_sync_error_notice() {
    $error = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
    if ( empty( $error ) ) {
        return;
    }
    ?>
    <div class="notice notice-error is-dismissible">
        <p><strong><?php esc_html_e( 'FlowHub Sync Error:', 'mcp-ai-wpoos-pro' ); ?></strong>
        <?php echo esc_html( $error ); ?></p>
    </div>
    <?php
    delete_option( 'wp_mcp_ai_flowhub_last_sync_error' );
}
```

**Acceptance Criteria:**
- [ ] Sync errors trigger admin email
- [ ] Dismissible admin notice appears on next page load
- [ ] Error is logged to WP_MCP_AI activity log
- [ ] Notice is cleared after dismissal

---

### Story 3.4 — WooCommerce Sync Stub

**File:** Method `run_wc_sync()` in `class-wp-mcp-ai-flowhub-sync-engine.php`

**Implementation (Phase 1 — read-only):**
```php
public static function run_wc_sync() {
    $settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );

    if ( empty( $settings['enable_wc_sync'] ) ) {
        return 0;
    }

    $cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
    $direction   = $settings['sync_direction'] ?? 'flowhub_to_woo';

    if ( 'flowhub_to_woo' === $direction ) {
        // Read FlowHub items from CCT
        // Match to WooCommerce products by SKU
        // Update stock_quantity via wc_update_product_stock()
        // Store linked woo_product_id in CCT row
        return self::sync_flowhub_to_woocommerce( $cct_manager );
    }

    if ( 'woo_to_flowhub' === $direction ) {
        // Future: Push WooCommerce stock changes back to FlowHub API
        // Requires careful conflict resolution
        return 0;  // Placeholder
    }

    return 0;
}

private static function sync_flowhub_to_woocommerce( $cct_manager ) {
    $updated = 0;
    $items   = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );

    foreach ( $items as $item ) {
        $product_id = wc_get_product_id_by_sku( $item['sku'] );
        if ( ! $product_id ) {
            continue;  // No matching WooCommerce product
        }

        wc_update_product_stock( $product_id, absint( $item['quantity'] ) );
        $cct_manager->update_woo_product_id( $item['_ID'], $product_id );
        $updated++;
    }

    return $updated;
}
```

**Acceptance Criteria:**
- [ ] WC sync is gated behind `enable_wc_sync` setting
- [ ] FlowHub→WC direction updates WooCommerce stock quantities
- [ ] WooCommerce product ID is stored back to CCT row
- [ ] `woo_to_flowhub` direction returns 0 (placeholder, not an error)

---

## Phase 4: Testing & Documentation (Week 1 — Stories 13–16)

**Goal:** Full test coverage, documentation, and CI readiness.

### Story 4.1 — Unit Tests

**Files:**
- `addons/pro/tests/test-flowhub-client.php`
- `addons/pro/tests/test-flowhub-cct-manager.php`
- `addons/pro/tests/test-flowhub-sync-engine.php`
- `addons/pro/tests/test-flowhub-tools.php`

**Test Categories:**

| Test | Coverage |
|---|---|
| **Client** | API call mocking, pagination, error handling, rate limiting, connection health |
| **CCT Manager** | Column creation, upsert, query filters, freshness, truncation |
| **Sync Engine** | Scheduling, full sync, WC sync, error notification |
| **Tools** | Capability gates, guest access rejection, argument sanitization, canonical envelope, CCT read path, API fallback, tool-to-CCT integration |
| **Security** | Capability checks, input sanitization, output escaping, credential encryption |

**PHPUnit Configuration:**
```xml
<phpunit>
    <testsuites>
        <testsuite name="FlowHub">
            <directory>addons/pro/tests/test-flowhub-*.php</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

**Acceptance Criteria:**
- [ ] > 80% line coverage on client, CCT manager, sync engine, and tools
- [ ] All capability gates tested (admin can access, subscriber cannot)
- [ ] All guest access paths tested (guests get proper 403)
- [ ] All WP_Error return paths tested
- [ ] All canonical envelope success paths tested
- [ ] Two-gate sanitisation verified (no raw $arguments usage)

---

### Story 4.2 — Tool Reference Documentation

**File:** Add FlowHub section to `docs/reference/tools/tool-reference.md`

**Content per tool:**
- Slug, name, description
- Parameter table (name, type, description, required, default, enum)
- Capability requirements
- Example usage (natural language prompts)
- Return shape

---

### Story 4.3 — Toolkit README

**File:** `addons/pro/includes/tools/flowhub/README.md`

Follow the established format from `addons/pro/includes/tools/erp-ezuite/README.md`:
- Purpose
- Tool Inventory table
- Dependencies
- Registration
- See Also links

---

### Story 4.4 — User-Facing Documentation

**File:** `docs/toolkits/flowhub-integration.md`

**Content:**
- What is FlowHub?
- Prerequisites (WooCommerce, JetEngine, FlowHub API credentials)
- Installation & activation
- Configuration walkthrough (screenshots)
- Tool usage examples
- Troubleshooting common issues
- Compliance notes

---

## Phase 5: Migration & Polish (Week 0.5 — Stories 17–19)

**Goal:** Smooth migration path from standalone plugin, compliance logging, and final review.

### Story 5.1 — Source Plugin Migration

**File:** `addons/pro/includes/class-wp-mcp-ai-flowhub-migration.php`

**Implementation:**
```php
class WP_MCP_AI_FlowHub_Migration {
    const SOURCE_PLUGIN = 'flowhub-inventory-sync/flowhub-inventory-sync.php';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'check_for_source_plugin' ) );
    }

    public static function check_for_source_plugin() {
        if ( ! is_plugin_active( self::SOURCE_PLUGIN ) ) {
            return;
        }
        add_action( 'admin_notices', array( __CLASS__, 'show_migration_notice' ) );
    }

    public static function show_migration_notice() {
        ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'FlowHub Inventory Sync (standalone plugin) is active. You can migrate your settings to the NV oOS Pro FlowHub Toolkit for AI-powered inventory management.', 'mcp-ai-wpoos-pro' ); ?></p>
            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wp-mcp-ai-flowhub-toolkit-settings&action=migrate' ), 'flowhub_migrate' ) ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Migrate Now', 'mcp-ai-wpoos-pro' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public static function migrate_settings() {
        // Read source plugin options
        $client_id = get_option( 'flowhub_client_id', '' );
        $api_key   = get_option( 'flowhub_key', '' );
        $cct_slug  = get_option( 'fis_cct', '' );
        $wc_enabled = get_option( 'fis_enable_wc_cron', 'no' );

        // Write to toolkit settings
        $settings = array(
            'client_id'      => $client_id,
            'api_key'        => $api_key,
            'cct_slug'       => $cct_slug,
            'enable_wc_sync' => ( 'yes' === $wc_enabled ) ? 'yes' : 'no',
            'sync_interval'  => 15, // Default
        );
        update_option( 'wp_mcp_ai_flowhub_toolkit_settings', $settings );

        // Enable toolkit
        $global_settings = get_option( 'wp_mcp_ai_settings', array() );
        $global_settings['enable_flowhub_toolkit'] = true;
        update_option( 'wp_mcp_ai_settings', $global_settings );

        // Log migration
        wp_mcp_ai_log( 'FlowHub settings migrated from standalone plugin.' );
    }
}
```

**Acceptance Criteria:**
- [ ] Admin notice appears when standalone plugin is active
- [ ] One-click migration imports client_id, key, CCT slug, WC sync setting
- [ ] Migration enables the FlowHub toolkit toggle
- [ ] Migration event is logged
- [ ] Source plugin data is NOT deleted (user manually deactivates)

---

### Story 5.2 — Compliance Audit Log

**File:** Extend `WP_MCP_AI_FlowHub_Sync_Engine::handle_sync_error()` + add sync event logging

**Implementation:**
```php
// Log every sync event:
wp_mcp_ai_log(
    sprintf(
        'FlowHub sync completed: %d items in %d locations. Duration: %ds.',
        $item_count,
        $location_count,
        $duration
    ),
    'info'
);

// Track compliance-relevant events:
do_action( 'wp_mcp_ai_flowhub_inventory_change', array(
    'product_id'   => $product_id,
    'sku'          => $sku,
    'old_quantity' => $old_qty,
    'new_quantity' => $new_qty,
    'location_id'  => $location_id,
    'timestamp'    => current_time( 'mysql' ),
) );
```

**Acceptance Criteria:**
- [ ] Every sync event is logged with item/location/duration metadata
- [ ] Inventory quantity changes fire `wp_mcp_ai_flowhub_inventory_change` hook
- [ ] Sync errors are logged with full context

---

### Story 5.3 — Final Review & PR

**Checklist:**
- [ ] All 19 stories pass acceptance criteria
- [ ] Full test suite passes (`vendor/bin/phpunit tests/test-flowhub-*.php`)
- [ ] PHPCS lint passes (`composer run lint`)
- [ ] PHP compatibility check passes (`composer run lint:compat`)
- [ ] Code review by agent (checking two-gate sanitisation, canonical envelope, capability gates)
- [ ] Tool reference documentation updated
- [ ] Toolkit README added
- [ ] User-facing documentation added
- [ ] Migration path tested end-to-end
- [ ] Pro README updated (toolkit listing)
- [ ] Changelog entry added

---

## Appendix A: Option Key Reference

| Option Key | Type | Default | Purpose |
|---|---|---|---|
| `wp_mcp_ai_settings[enable_flowhub_toolkit]` | bool | `false` | Master toolkit toggle |
| `wp_mcp_ai_flowhub_toolkit_settings[client_id]` | string | `''` | FlowHub API client ID |
| `wp_mcp_ai_flowhub_toolkit_settings[api_key]` | string | `''` | FlowHub API key (encrypted) |
| `wp_mcp_ai_flowhub_toolkit_settings[api_base_url]` | string | `'https://api.flowhub.co/v0/'` | API base URL |
| `wp_mcp_ai_flowhub_toolkit_settings[sync_interval]` | int | `15` | Minutes between syncs |
| `wp_mcp_ai_flowhub_toolkit_settings[sync_direction]` | string | `'flowhub_to_woo'` | `flowhub_to_woo` / `woo_to_flowhub` / `bidirectional` |
| `wp_mcp_ai_flowhub_toolkit_settings[enable_wc_sync]` | bool | `false` | Enable WooCommerce stock writeback |
| `wp_mcp_ai_flowhub_toolkit_settings[cct_slug]` | string | `'flowhub_inventory'` | JetEngine CCT slug |
| `wp_mcp_ai_flowhub_toolkit_settings[field_mapping]` | array | `[]` | Custom field mapping overrides |
| `wp_mcp_ai_flowhub_toolkit_settings[low_stock_threshold]` | int | `5` | "Low Stock" badge threshold |
| `wp_mcp_ai_flowhub_last_sync` | string | `''` | ISO 8601 timestamp |
| `wp_mcp_ai_flowhub_last_sync_error` | string | `''` | Transient error for admin notice |
| `wp_mcp_ai_flowhub_sync_db_version` | string | `'1.0'` | Schema version tracker |

## Appendix B: File Manifest

```
New files (23):
├── addons/pro/includes/
│   ├── class-wp-mcp-ai-flowhub-client.php
│   ├── class-wp-mcp-ai-flowhub-cct-manager.php
│   ├── class-wp-mcp-ai-flowhub-sync-engine.php
│   ├── class-wp-mcp-ai-flowhub-migration.php
│   └── admin/
│       └── class-wp-mcp-ai-flowhub-toolkit-settings-page.php
├── addons/pro/includes/tools/flowhub/
│   ├── README.md
│   ├── init.php
│   ├── class-wp-mcp-ai-pro-tool-flowhub-inventory.php
│   ├── class-wp-mcp-ai-pro-tool-flowhub-products.php
│   ├── class-wp-mcp-ai-pro-tool-flowhub-locations.php
│   ├── class-wp-mcp-ai-pro-tool-flowhub-sync.php
│   ├── class-wp-mcp-ai-pro-tool-flowhub-settings.php
│   └── trait-wp-mcp-ai-flowhub-connection-resolver.php
└── addons/pro/tests/
    ├── test-flowhub-client.php
    ├── test-flowhub-cct-manager.php
    ├── test-flowhub-sync-engine.php
    └── test-flowhub-tools.php

Modified files (3):
├── addons/pro/mcp-ai-wpoos-pro.php           ← wp_mcp_ai_pro_register_tools()
├── addons/pro/README.md                       ← Toolkit listing
├── docs/reference/tools/tool-reference.md     ← Tool entries

New docs (3):
├── docs/project/proposals/
│   ├── FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md
│   └── FLOWHUB-INVENTORY-SYNC-IMPLEMENTATION-PLAN.md
└── docs/toolkits/flowhub-integration.md
```

## Appendix C: Deactivation & Uninstall Behavior

| Event | What Happens |
|---|---|
| **Toolkit toggle OFF** | Tools unregistered. Admin page hidden. Sync continues. Options preserved. |
| **Plugin deactivated** | Action Scheduler hooks cleared. Options preserved. CCT data preserved. |
| **Plugin uninstalled** | All `wp_mcp_ai_flowhub_*` options removed. CCT table is JetEngine's — not touched. Scheduled actions cleared. |

Following the `wp-plugin-lifecycle` skill: **never delete user data on deactivation**. CCT data belongs to JetEngine and persists.

---

## Appendix D: Difference from Source Plugin

| Component | Source Plugin | Pro Toolkit Integration |
|---|---|---|
| Sync table | `wp_flowhub_sync_data` (custom) | JetEngine CCT only |
| Cron | 5 WP-Cron intervals (`fis_1min`–`fis_60min`) | 1 Action Scheduler recurring action |
| CCT sync | Separate cron hook | Same sync engine (unified path) |
| WC sync | Stub function (returns 0) | Implemented (configurable direction) |
| Frontend | `[fis_inventory]` React SPA | Preserved as optional shortcode |
| AI tools | None | 5 LLM-callable tools |
| Admin UI | Settings → FlowHub Sync | Top-level FlowHub Toolkit menu |
| Error handling | `fis_notify_error()` + admin notice | `WP_MCP_AI_Logger` + admin notice (same pattern) |
| Credential storage | Plain WP options | Encrypted via `WP_MCP_AI_Credentials` |
| Field mapping | Hard-coded in schema | Configurable in settings |
| Tool registry | N/A | Registered via `WP_MCP_AI_Tool_Registry` |
