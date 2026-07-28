# Implementation Plan: Status Page, Maintenance Announcements & Incident Communication Workflow

**Based on:** Proposal 014 (`docs/project/proposals/014-status-page-maintenance-incident-communication.md`)
**Date:** 2026-07-28
**Status:** Draft
**Target release:** v1.2.0 (Phase 1), v1.3.0 (Phase 2), v1.4.0 (Phase 3)

---

## Executive Summary

Three implementation phases across the Base plugin (PHP 7.4+) and Pro addon (PHP 8.1+). Phase 1 delivers the status page foundation — a public REST API, `[nvoos_status]` shortcode, service component registry, and Pro admin dashboard. Phase 2 adds scheduled maintenance windows with frontend banners and multi-channel notifications. Phase 3 implements the incident communication workflow with phase state machine, timeline, and AI tools.

**Total new files (Phase 1):** ~8 base + ~5 pro
**Total new files (Phases 2–3):** ~16 pro
**Files modified:** 2 base (loader.php, activation.php)
**Total estimated LOC:** ~4,500–5,500 across all phases

---

## Phase 1: Status Page Foundation

### File Inventory

#### Base Plugin — New Files

| # | File | Purpose |
|---|---|---|
| 1.1 | `includes/interfaces/interface-wp-mcp-ai-service-status-source.php` | Contract for service components to report health |
| 1.2 | `includes/services/class-wp-mcp-ai-service-status-registry.php` | Collects registered sources, runs health checks, stores aggregated status |
| 1.3 | `includes/services/class-wp-mcp-ai-service-status-default-sources.php` | Built-in health probes for AI providers, tool registry, and queue health |
| 1.4 | `includes/rest/class-wp-mcp-ai-status-rest-controller.php` | REST controller: public status endpoints |
| 1.5 | `includes/class-wp-mcp-ai-status-shortcode.php` | `[nvoos_status]` shortcode for frontend rendering |
| 1.6 | `includes/class-wp-mcp-ai-service-status-cpt.php` | `mcp_ai_service` CPT registration |

#### Base Plugin — Modified Files

| # | File | Change |
|---|---|---|
| 1.7 | `includes/bootstrap/loader.php` | Require new files; register REST routes on `rest_api_init`; register shortcode on `init` |
| 1.8 | `includes/bootstrap/activation.php` | Schedule `wp_mcp_ai_health_check_cron` (every 5 min) and `wp_mcp_ai_uptime_rollup_cron` (hourly); register cleanup on deactivation |

#### Pro Addon — New Files

| # | File | Purpose |
|---|---|---|
| 1.9 | `addons/pro/includes/admin/class-wp-mcp-ai-pro-status-dashboard-page.php` | Admin dashboard page under Pro Dashboard menu |
| 1.10 | `addons/pro/includes/admin/class-wp-mcp-ai-pro-status-ajax.php` | AJAX handlers: refresh status, trigger health check, toggle component visibility |
| 1.11 | `addons/pro/assets/css/pro-status-page.css` | Admin status dashboard styles |
| 1.12 | `addons/pro/assets/js/pro-status-page.js` | Live-refresh status grid, Chart.js uptime graph |

---

### Task 1.1 — Define `Interface_WP_MCP_AI_Service_Status_Source`

**File:** `includes/interfaces/interface-wp-mcp-ai-service-status-source.php`

```php
interface Interface_WP_MCP_AI_Service_Status_Source {
    public function get_slug();
    public function get_name();
    public function get_group();
    public function check_health();
    public function is_public();
}
```

**Validation:**
```bash
composer run lint:errors-only -- --filter=gitmodified
```

**Checklist:**
- [ ] PHPDoc on interface and every method with `@since 1.2.0`
- [ ] `@package WP_MCP_AI`
- [ ] ABSPATH guard
- [ ] `check_health()` return type documented as `array{status: string, latency_ms: int|null, message: string, checked_at: int}` in PHPDoc (no union return types — PHP 7.4 compat)
- [ ] No PHP 8.0+ syntax (no named arguments, no match expressions, no union types)

---

### Task 1.2 — Build `WP_MCP_AI_Service_Status_Registry`

**File:** `includes/services/class-wp-mcp-ai-service-status-registry.php`

**Key methods:**

```php
class WP_MCP_AI_Service_Status_Registry {
    const OPTION_KEY = 'wp_mcp_ai_service_status';
    const HISTORY_KEY = 'wp_mcp_ai_service_uptime_history';
    const LAST_CHECK_KEY = 'wp_mcp_ai_last_health_check';

    private static $instance = null;

    public static function get_instance() { /* singleton */ }

    /**
     * Collect all registered service status sources.
     *
     * @return array<string, Interface_WP_MCP_AI_Service_Status_Source>
     */
    public function get_sources() {
        return apply_filters( 'wp_mcp_ai_service_status_sources', array() );
    }

    /**
     * Run health checks for all registered sources.
     *
     * @return array<string, array> Map of slug => health check result.
     */
    public function run_health_checks() {
        $results = array();
        foreach ( $this->get_sources() as $slug => $source ) {
            $results[ $slug ] = $source->check_health();
        }
        update_option( self::OPTION_KEY, $results, false );
        update_option( self::LAST_CHECK_KEY, time(), false );
        return $results;
    }

    /**
     * Get the cached (last) status snapshot.
     *
     * @return array
     */
    public function get_status() {
        $status = get_option( self::OPTION_KEY, array() );
        if ( empty( $status ) ) {
            $status = $this->run_health_checks();
        }
        return $status;
    }

    /**
     * Get status for public consumption (allowlisted fields only).
     *
     * @return array
     */
    public function get_public_status() {
        $status  = $this->get_status();
        $sources = $this->get_sources();
        $public  = array();
        foreach ( $status as $slug => $data ) {
            if ( ! isset( $sources[ $slug ] ) || ! $sources[ $slug ]->is_public() ) {
                continue;
            }
            $public[ $slug ] = array(
                'slug'        => $slug,
                'name'        => $sources[ $slug ]->get_name(),
                'group'       => $sources[ $slug ]->get_group(),
                'status'      => $data['status'],
                'message'     => $data['message'],
                'checked_at'  => $data['checked_at'],
            );
        }
        return $public;
    }
}
```

**Checklist:**
- [ ] Singleton pattern matching existing plugin classes
- [ ] Uses `update_option( ..., false )` — no autoload for status data
- [ ] `get_public_status()` strips `latency_ms` and any internal fields
- [ ] Filters non-conforming sources from `wp_mcp_ai_service_status_sources` (defensive; matches `Cron_Status_Service` pattern)
- [ ] `run_health_checks()` wraps each source call in try/catch (PHP 7.4: no `Throwable` union needed — just catch `Exception`)
- [ ] Option keys follow naming convention: `wp_mcp_ai_service_status`, `wp_mcp_ai_service_uptime_history`
- [ ] All strings translatable with `mcp-ai-wpoos` text domain

---

### Task 1.3 — Build Default Service Status Sources

**File:** `includes/services/class-wp-mcp-ai-service-status-default-sources.php`

Three built-in sources registered via `wp_mcp_ai_service_status_sources`:

#### 1. AI Provider Status (`ai_providers`)

Probes each configured AI provider's availability:

```php
class WP_MCP_AI_Service_Status_AI_Providers implements Interface_WP_MCP_AI_Service_Status_Source {
    public function get_slug()  { return 'ai_providers'; }
    public function get_name()  { return __( 'AI Providers', 'mcp-ai-wpoos' ); }
    public function get_group() { return 'ai_services'; }
    public function is_public() { return true; }

    public function check_health() {
        // Iterate configured providers; ping a lightweight endpoint (e.g. models.list)
        // Return aggregated status: operational if all up, degraded if some down, major_outage if all down
    }
}
```

#### 2. Tool Registry Health (`tool_registry`)

Checks tool availability without executing any:

```php
class WP_MCP_AI_Service_Status_Tool_Registry implements Interface_WP_MCP_AI_Service_Status_Source {
    public function get_slug()  { return 'tool_registry'; }
    public function get_name()  { return __( 'Tool Registry', 'mcp-ai-wpoos' ); }
    public function get_group() { return 'infrastructure'; }
    public function is_public() { return true; }

    public function check_health() {
        // Count registered tools; verify registry is accessible
    }
}
```

#### 3. Queue Health (`queue_health`)

Checks job queue depth and oldest pending job age:

```php
class WP_MCP_AI_Service_Status_Queue_Health implements Interface_WP_MCP_AI_Service_Status_Source {
    public function get_slug()  { return 'queue_health'; }
    public function get_name()  { return __( 'Job Queue', 'mcp-ai-wpoos' ); }
    public function get_group() { return 'infrastructure'; }
    public function is_public() { return false; } // Internal only by default

    public function check_health() {
        // Use existing Cron_Status_Service to query queue depth
    }
}
```

**Checklist:**
- [ ] Each source class in its own file under `includes/services/status-sources/` (or inline in the default-sources file if small enough)
- [ ] Health probes use `wp_safe_remote_get()` with 10-second timeout
- [ ] Probe failures return `major_outage` with descriptive message
- [ ] All strings translatable
- [ ] No probe makes state-changing requests

---

### Task 1.4 — Build REST Controller

**File:** `includes/rest/class-wp-mcp-ai-status-rest-controller.php`

```php
class WP_MCP_AI_Status_REST_Controller extends WP_REST_Controller {
    public function register_routes() {
        register_rest_route( 'mcp-ai/v1', '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_full_status' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'include_private' => array(
                    'type'              => 'boolean',
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ),
            ),
        ) );
        // /status/components, /status/incidents, /status/maintenance, /status/history
    }

    public function get_full_status( $request ) {
        $registry = WP_MCP_AI_Service_Status_Registry::get_instance();

        $response = array(
            'components'  => $registry->get_public_status(),
            'incidents'   => $this->get_active_incidents(),
            'maintenance' => $this->get_upcoming_maintenance(),
            'overall'     => $this->compute_overall_status( $registry->get_public_status() ),
        );

        // Gate detailed status behind manage_options (PR #5718 pattern)
        if ( $request->get_param( 'include_private' ) && current_user_can( 'manage_options' ) ) {
            $response['components'] = $registry->get_status(); // Full data
        }

        return rest_ensure_response( $response );
    }
}
```

**Checklist:**
- [ ] `permission_callback` is `__return_true` for GET (public), never for state-changing routes
- [ ] Private fields gated behind `manage_options` + explicit `include_private` param
- [ ] `WP_REST_Server::READABLE` constant (PHP 7.4 compat — no enum)
- [ ] Rate limiting via existing `WP_MCP_AI_SSE_Rate_Limiter` or a lightweight transient-based limiter
- [ ] Response field allowlisting — never return raw option data
- [ ] Cache headers: `Cache-Control: public, max-age=60` (1-minute public cache) for unauthenticated requests
- [ ] `get_active_incidents()` and `get_upcoming_maintenance()` return empty arrays in Phase 1 (CPTs don't exist yet)

---

### Task 1.5 — Build Shortcode

**File:** `includes/class-wp-mcp-ai-status-shortcode.php`

```php
class WP_MCP_AI_Status_Shortcode {
    public static function render( $atts ) {
        $atts = shortcode_atts( array(
            'show_incidents'   => 'true',
            'show_maintenance' => 'true',
            'show_history'     => 'false',
            'compact'          => 'false',
        ), $atts, 'nvoos_status' );

        $registry = WP_MCP_AI_Service_Status_Registry::get_instance();
        $status   = $registry->get_public_status();

        ob_start();
        // Render component grid with status badges
        // - operational: green
        // - degraded_performance: yellow
        // - partial_outage: orange
        // - major_outage: red
        // - under_maintenance: blue
        echo '<div class="nvoos-status-page' . ( 'true' === $atts['compact'] ? ' nvoos-status-compact' : '' ) . '">';
        // ... render components
        echo '</div>';
        return ob_get_clean();
    }
}
```

**Checklist:**
- [ ] Shortcode registered on `init` in loader.php
- [ ] All output escaped: `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for links
- [ ] Status badge CSS classes use existing health badge naming: `ok`, `warning`, `error` (matches Webhook Status Page pattern)
- [ ] Empty state: "No service components are currently being monitored."
- [ ] `show_history` guarded for Phase 2 (not yet implemented — renders nothing if true)
- [ ] `show_incidents` and `show_maintenance` guarded for Phase 2–3 (renders nothing if true)

---

### Task 1.6 — Register `mcp_ai_service` CPT

**File:** `includes/class-wp-mcp-ai-service-status-cpt.php`

Minimal CPT for Phase 1 — primarily used as a data model for the Pro admin UI:

```php
class WP_MCP_AI_Service_Status_CPT {
    const POST_TYPE = 'mcp_ai_service';

    public static function register() {
        register_post_type( self::POST_TYPE, array(
            'labels'              => array(
                'name'          => __( 'Service Components', 'mcp-ai-wpoos' ),
                'singular_name' => __( 'Service Component', 'mcp-ai-wpoos' ),
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false, // Managed by Pro admin page
            'show_in_rest'        => true,
            'supports'            => array( 'title' ),
            'capability_type'     => 'post',
            'capabilities'        => array( 'create_posts' => 'manage_options' ),
            'map_meta_cap'        => true,
        ) );

        register_post_meta( self::POST_TYPE, '_mcp_ai_service_slug', array(
            'type'        => 'string',
            'single'      => true,
            'show_in_rest'=> true,
        ) );
        register_post_meta( self::POST_TYPE, '_mcp_ai_service_group', array(
            'type'        => 'string',
            'single'      => true,
            'show_in_rest'=> true,
        ) );
        register_post_meta( self::POST_TYPE, '_mcp_ai_service_status', array(
            'type'        => 'string',
            'single'      => true,
            'default'     => 'operational',
            'show_in_rest'=> true,
        ) );
        register_post_meta( self::POST_TYPE, '_mcp_ai_service_public', array(
            'type'        => 'boolean',
            'single'      => true,
            'default'     => true,
            'show_in_rest'=> true,
        ) );
    }
}
```

**Checklist:**
- [ ] CPT slug follows naming convention: `mcp_ai_service`
- [ ] `public => false` — not frontend-facing; status data served via REST/shortcode
- [ ] `show_in_rest => true` for Gutenberg/API compatibility
- [ ] All post meta keys follow naming convention: `_mcp_ai_service_{field}`
- [ ] `show_in_menu => false` — Pro dashboard provides the admin UI

---

### Task 1.7 — Update Loader

**File:** `includes/bootstrap/loader.php`

Add the following require statements in the appropriate sections (following the existing organization):

```php
// Interfaces
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-service-status-source.php';

// Services
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-registry.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-service-status-default-sources.php';

// REST
require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-status-rest-controller.php';

// CPT
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-service-status-cpt.php';

// Shortcode
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-status-shortcode.php';
```

Add hook registrations:

```php
// CPT registration
add_action( 'init', array( 'WP_MCP_AI_Service_Status_CPT', 'register' ), 11 );

// REST routes
add_action( 'rest_api_init', function () {
    $controller = new WP_MCP_AI_Status_REST_Controller();
    $controller->register_routes();
} );

// Shortcode
add_action( 'init', function () {
    add_shortcode( 'nvoos_status', array( 'WP_MCP_AI_Status_Shortcode', 'render' ) );
} );

// Register default service status sources
add_filter( 'wp_mcp_ai_service_status_sources', function ( $sources ) {
    $sources['ai_providers']  = new WP_MCP_AI_Service_Status_AI_Providers();
    $sources['tool_registry'] = new WP_MCP_AI_Service_Status_Tool_Registry();
    $sources['queue_health']  = new WP_MCP_AI_Service_Status_Queue_Health();
    return $sources;
} );
```

**Checklist:**
- [ ] New `require_once` calls placed in the correct section (services, rest, etc.) following existing file organization
- [ ] CPT registration priority 11 (after assistants CPT at 10)
- [ ] Anonymous function closures OK for hook callbacks (PHP 7.4+ supports them)
- [ ] No duplicate hook registrations

---

### Task 1.8 — Update Activation

**File:** `includes/bootstrap/activation.php`

Add to `wp_mcp_ai_activate_single_site()`:

```php
// Schedule health check cron.
if ( ! wp_next_scheduled( 'wp_mcp_ai_health_check_cron' ) ) {
    wp_schedule_event( time(), 'five_minutes', 'wp_mcp_ai_health_check_cron' );
}

// Schedule uptime rollup cron.
if ( ! wp_next_scheduled( 'wp_mcp_ai_uptime_rollup_cron' ) ) {
    wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_uptime_rollup_cron' );
}

// Schedule history cleanup cron.
if ( ! wp_next_scheduled( 'wp_mcp_ai_status_history_cleanup' ) ) {
    wp_schedule_event( time(), 'daily', 'wp_mcp_ai_status_history_cleanup' );
}
```

Add to `wp_mcp_ai_deactivate_single_site()`:

```php
wp_clear_scheduled_hook( 'wp_mcp_ai_health_check_cron' );
wp_clear_scheduled_hook( 'wp_mcp_ai_uptime_rollup_cron' );
wp_clear_scheduled_hook( 'wp_mcp_ai_status_history_cleanup' );
```

Add to `wp_mcp_ai_uninstall_single_site()`:

```php
delete_option( 'wp_mcp_ai_service_status' );
delete_option( 'wp_mcp_ai_service_uptime_history' );
delete_option( 'wp_mcp_ai_last_health_check' );
```

Register `five_minutes` cron schedule if not already defined:

```php
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['five_minutes'] ) ) {
        $schedules['five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'mcp-ai-wpoos' ),
        );
    }
    return $schedules;
} );
```

**Checklist:**
- [ ] `wp_next_scheduled()` guard before `wp_schedule_event()` (existing pattern in activation.php)
- [ ] Cron hooks cleared on deactivation
- [ ] Options deleted on uninstall (not deactivation — matches plugin lifecycle rules)
- [ ] Multisite-aware: activation loop with `switch_to_blog()` if `$network_wide` is true (following existing pattern)

---

### Task 1.9 — Build Pro Admin Status Dashboard

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-status-dashboard-page.php`

Follows the Webhook Status Page pattern (`class-wp-mcp-ai-pro-webhook-status-page.php`):

```php
class WP_MCP_AI_Pro_Status_Dashboard_Page {
    const PAGE_SLUG = 'nvoos-pro-status';

    private $page_hook = '';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_page' ), 28 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function register_page() {
        $this->page_hook = add_submenu_page(
            'nvoos-pro-dashboard',
            __( 'Status Page', 'mcp-ai-wpoos-pro' ),
            __( 'Status Page', 'mcp-ai-wpoos-pro' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        // Component grid with health badges
        // Uptime history chart (Chart.js)
        // Manual health check trigger button
        // Link to public status page preview
    }
}
```

**Checklist:**
- [ ] Uses priority 28 for `admin_menu` (after Command Center at 27, following existing convention)
- [ ] Nonce on all AJAX actions: `wp_create_nonce( 'wp_mcp_ai_status_dashboard' )`
- [ ] Health badge CSS classes: `ok`, `warning`, `error`, `maintenance`, `unknown` (matching Webhook Status Page)
- [ ] Strings use `mcp-ai-wpoos-pro` text domain
- [ ] Asset enqueue uses `WP_MCP_AI_PRO_URL` and `WP_MCP_AI_PRO_VERSION` constants
- [ ] Chart.js loaded from CDN (following Command Center pattern)

---

### Task 1.10 — Build Pro AJAX Handlers

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-status-ajax.php`

```php
class WP_MCP_AI_Pro_Status_Ajax {
    const NONCE_ACTION = 'wp_mcp_ai_status_dashboard';

    public function __construct() {
        add_action( 'wp_ajax_wp_mcp_ai_status_refresh', array( $this, 'handle_refresh' ) );
        add_action( 'wp_ajax_wp_mcp_ai_status_health_check', array( $this, 'handle_health_check' ) );
        add_action( 'wp_ajax_wp_mcp_ai_status_toggle_public', array( $this, 'handle_toggle_public' ) );
    }

    public function handle_refresh() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
        }
        $registry = WP_MCP_AI_Service_Status_Registry::get_instance();
        wp_send_json_success( $registry->get_status() );
    }

    // handle_health_check: triggers run_health_checks(), returns fresh results
    // handle_toggle_public: updates _mcp_ai_service_public post meta for a component
}
```

**Checklist:**
- [ ] `check_ajax_referer()` on every handler
- [ ] `current_user_can( 'manage_options' )` on every handler
- [ ] `wp_send_json_success()` / `wp_send_json_error()` with proper HTTP status codes
- [ ] Nonce action follows naming: `wp_mcp_ai_status_dashboard`

---

### Task 1.11 — Build Pro Assets

**CSS:** `addons/pro/assets/css/pro-status-page.css`

- Component grid layout (CSS Grid, responsive)
- Health badge styles (ok/warning/error/maintenance/unknown)
- Status card hover/transition effects
- Uptime chart container sizing
- Loading/skeleton states

**JS:** `addons/pro/assets/js/pro-status-page.js`

- `wpMcpAiStatus` namespaced object
- `refreshStatus()` — AJAX call to `wp_mcp_ai_status_refresh`
- `triggerHealthCheck()` — AJAX call with loading state
- `toggleComponentVisibility()` — AJAX call to `wp_mcp_ai_status_toggle_public`
- Uptime chart initialization (Chart.js, 30-day rolling)
- Auto-refresh interval (60 seconds, matching REST cache TTL)

**Checklist:**
- [ ] JS follows WordPress ESLint rules (tabs, single quotes)
- [ ] `wp_add_inline_script()` for localized data (ajaxUrl, nonce, refreshInterval)
- [ ] CSS uses `nvoos-` or `wp-mcp-ai-` prefix for all classes
- [ ] No hardcoded CDN URLs — use `WP_MCP_AI_PRO_URL` constant

---

## Phase 2: Maintenance Announcement System

### File Inventory

| # | File | Purpose |
|---|---|---|
| 2.1 | `addons/pro/includes/class-wp-mcp-ai-maintenance-cpt.php` | `mcp_ai_maintenance` CPT + meta + status transitions |
| 2.2 | `addons/pro/includes/class-wp-mcp-ai-maintenance-rest.php` | REST CRUD for maintenance windows |
| 2.3 | `addons/pro/includes/class-wp-mcp-ai-maintenance-banner.php` | Frontend banner shortcode + auto-inject |
| 2.4 | `addons/pro/includes/class-wp-mcp-ai-maintenance-notifier.php` | Email + webhook + channel broadcast notifications |
| 2.5 | `addons/pro/includes/admin/class-wp-mcp-ai-pro-maintenance-page.php` | Admin calendar/list page |
| 2.6 | `addons/pro/assets/css/pro-maintenance.css` | Banner + admin styles |
| 2.7 | `addons/pro/assets/js/pro-maintenance.js` | Countdown timer + calendar JS |

### Task 2.1 — Build Maintenance CPT

**File:** `addons/pro/includes/class-wp-mcp-ai-maintenance-cpt.php`

```php
class WP_MCP_AI_Maintenance_CPT {
    const POST_TYPE = 'mcp_ai_maintenance';

    const STATUS_SCHEDULED  = 'scheduled';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_CANCELLED  = 'cancelled';

    // Register CPT, meta fields, cron hooks for auto-transition
}
```

**Post meta fields:**
- `_mcp_ai_maintenance_start` — datetime
- `_mcp_ai_maintenance_end` — datetime
- `_mcp_ai_maintenance_services` — array of service slugs
- `_mcp_ai_maintenance_notify_channels` — array of channel identifiers
- `_mcp_ai_maintenance_notify_before` — minutes (int)
- `_mcp_ai_maintenance_banner_enabled` — boolean

**Status transitions (cron-driven):**
- `scheduled` → `in_progress`: when `start` time is reached (`wp_mcp_ai_maintenance_monitor_cron`, every 1 minute)
- `in_progress` → `completed`: when `end` time is reached
- Any status → `cancelled`: manual operator action

**Checklist:**
- [ ] CPT `public => false`, `show_in_rest => true`
- [ ] All post meta `show_in_rest => true` for Gutenberg compatibility
- [ ] `wp_mcp_ai_maintenance_started` action hook fired on `scheduled` → `in_progress`
- [ ] `wp_mcp_ai_maintenance_completed` action hook fired on `in_progress` → `completed`
- [ ] Status transition validation: cannot go `completed` → `in_progress`, etc.
- [ ] Uses PHP 8.1+ features where appropriate (enums for status constants, readonly properties)

---

### Task 2.2 — Build Maintenance REST Controller

**File:** `addons/pro/includes/class-wp-mcp-ai-maintenance-rest.php`

**Endpoints (Pro-only, `manage_options`):**

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/mcp-ai-pro/v1/maintenance` | List all windows (with status filter) |
| `POST` | `/mcp-ai-pro/v1/maintenance` | Create a new window |
| `PUT` | `/mcp-ai-pro/v1/maintenance/{id}` | Update a window |
| `DELETE` | `/mcp-ai-pro/v1/maintenance/{id}` | Cancel a window |

**Public endpoint (Base, `__return_true`):**
| Method | Route | Purpose |
|---|---|---|
| `GET` | `/mcp-ai/v1/status/maintenance` | List upcoming + in-progress windows (public) |

**Checklist:**
- [ ] `permission_callback` checks `manage_options` for all write endpoints
- [ ] `args` schema with `validate_callback` and `sanitize_callback` for every parameter
- [ ] Date fields validated as ISO 8601
- [ ] Cannot create overlapping maintenance windows for the same service (validation rule)
- [ ] Public endpoint returns only `title`, `content`, `start`, `end`, `services` — no internal fields

---

### Task 2.3 — Build Frontend Banner

**File:** `addons/pro/includes/class-wp-mcp-ai-maintenance-banner.php`

```php
class WP_MCP_AI_Maintenance_Banner {
    /**
     * Check if any maintenance window is currently active and render banner.
     *
     * Hooks into wp_footer or can be placed via shortcode [nvoos_maintenance_banner].
     */
    public static function maybe_render_banner() {
        $active = self::get_active_window();
        if ( ! $active ) {
            return;
        }
        // Render dismissible banner with countdown timer
        // Styled as a sticky top bar or bottom bar
    }

    public static function get_active_window() {
        // Query mcp_ai_maintenance CPT for status=in_progress
    }
}
```

**Checklist:**
- [ ] Banner is dismissible (stores dismissal in user meta or session cookie)
- [ ] Countdown timer displays time remaining until window end
- [ ] Banner re-appears on page reload if still in window
- [ ] CSS is minimal and overridable via theme
- [ ] `wp_kses_post()` on `post_content` before rendering
- [ ] Escape all output: `esc_html()`, `esc_attr()`

---

### Task 2.4 — Build Maintenance Notifier

**File:** `addons/pro/includes/class-wp-mcp-ai-maintenance-notifier.php`

Hooks into:
- `wp_mcp_ai_maintenance_scheduled` → send "upcoming maintenance" notification
- `wp_mcp_ai_maintenance_started` → send "maintenance started" notification
- `wp_mcp_ai_maintenance_completed` → send "maintenance completed" notification
- `wp_mcp_ai_maintenance_reminder` → send pre-maintenance reminder (fired by reminder cron)

Channels (via existing infrastructure):
- Email: `wp_mail()` to admin email + configurable recipient list
- Webhook: `WP_MCP_AI_Outbound_Webhook::get_instance()->dispatch()`
- Channel Broadcast: `WP_MCP_AI_Section_Schedule_Manager` integration (Telegram, Slack, Discord, etc.)

---

### Task 2.5 — Build Admin Maintenance Page

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-maintenance-page.php`

- Calendar view of scheduled windows (month view)
- List view with status filter
- Create/edit form with datetime pickers, service multi-select, channel checkboxes
- "Cancel" button with confirmation dialog

**Checklist:**
- [ ] Registered under `nvoos-pro-dashboard` parent slug
- [ ] Priority 29 for `admin_menu` (after Status Page at 28)
- [ ] Nonce on all form submissions
- [ ] `manage_options` capability gate

---

## Phase 3: Incident Communication Workflow

### File Inventory

| # | File | Purpose |
|---|---|---|
| 3.1 | `addons/pro/includes/class-wp-mcp-ai-incident-cpt.php` | `mcp_ai_incident` CPT + phase state machine |
| 3.2 | `addons/pro/includes/class-wp-mcp-ai-incident-rest.php` | REST CRUD + phase transitions |
| 3.3 | `addons/pro/includes/class-wp-mcp-ai-incident-notifier.php` | Phase-aware notification dispatcher |
| 3.4 | `addons/pro/includes/class-wp-mcp-ai-incident-lesson-bridge.php` | Incident → Lesson linkage |
| 3.5 | `addons/pro/includes/admin/class-wp-mcp-ai-pro-incidents-page.php` | Admin incidents list + editor |
| 3.6 | `addons/pro/includes/tools/class-wp-mcp-ai-tool-get-service-status.php` | AI tool: `get_service_status` |
| 3.7 | `addons/pro/includes/tools/class-wp-mcp-ai-tool-create-incident.php` | AI tool: `create_incident` |
| 3.8 | `addons/pro/includes/tools/class-wp-mcp-ai-tool-update-incident.php` | AI tool: `update_incident` |
| 3.9 | `addons/pro/includes/tools/class-wp-mcp-ai-tool-resolve-incident.php` | AI tool: `resolve_incident` |
| 3.10 | `addons/pro/includes/tools/class-wp-mcp-ai-tool-schedule-maintenance.php` | AI tool: `schedule_maintenance` |
| 3.11 | `addons/pro/assets/css/pro-incidents.css` | Incident timeline + admin styles |
| 3.12 | `addons/pro/assets/js/pro-incidents.js` | Incident editor + timeline JS |

### Task 3.1 — Build Incident CPT with Phase State Machine

**File:** `addons/pro/includes/class-wp-mcp-ai-incident-cpt.php`

**Phase constants (PHP 8.1 enum):**

```php
enum WP_MCP_AI_Incident_Phase: string {
    case DETECTED      = 'detected';
    case INVESTIGATING = 'investigating';
    case IDENTIFIED    = 'identified';
    case MONITORING    = 'monitoring';
    case RESOLVED      = 'resolved';
}
```

**Valid transitions:**
- `detected` → `investigating`
- `investigating` → `identified`
- `identified` → `monitoring`
- `monitoring` → `resolved`
- Any phase → `resolved` (emergency skip)
- `resolved` → (terminal; no further transitions)

**Timeline post meta** (`_mcp_ai_incident_timeline`):

```php
// Each entry:
array(
    'timestamp'   => time(),
    'phase'       => 'investigating',
    'message'     => 'Investigating elevated error rates on OpenAI API.',
    'operator_id' => get_current_user_id(),
)
```

**Checklist:**
- [ ] CPT `public => false`, `show_in_rest => true`
- [ ] `register_post_status()` for each phase (or use post_status for `resolved` and meta for others)
- [ ] Phase transition validation — reject invalid transitions
- [ ] `wp_mcp_ai_incident_phase_changed` action on every transition
- [ ] `wp_mcp_ai_incident_resolved` action on transition to `resolved`
- [ ] Timeline is append-only (no editing past entries, only adding new ones)
- [ ] `post_status` of `publish` while unresolved, changes to a custom `resolved` status on resolution

---

### Task 3.2 — Build Incident REST Controller

**File:** `addons/pro/includes/class-wp-mcp-ai-incident-rest.php`

**Endpoints (Pro-only, `manage_options`):**

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/mcp-ai-pro/v1/incidents` | List incidents (filter by phase, severity, date) |
| `POST` | `/mcp-ai-pro/v1/incidents` | Create new incident |
| `PUT` | `/mcp-ai-pro/v1/incidents/{id}` | Update incident (phase, message, severity) |
| `POST` | `/mcp-ai-pro/v1/incidents/{id}/resolve` | Resolve incident (convenience endpoint) |

**Public endpoint (Base, `__return_true`):**
| Method | Route | Purpose |
|---|---|---|
| `GET` | `/mcp-ai/v1/status/incidents` | Active incidents only (public, allowlisted fields) |

**Checklist:**
- [ ] Phase transition validation on update
- [ ] Timeline auto-appended on phase change (server-side, not client-driven)
- [ ] `resolve` endpoint fires `wp_mcp_ai_incident_resolved` action
- [ ] Public endpoint returns only `id`, `title`, `phase`, `severity`, `services`, `timeline` (no operator_id)
- [ ] REST schema uses `WP_MCP_AI_Incident_Phase` enum for validation

---

### Task 3.3 — Build Incident Notifier

**File:** `addons/pro/includes/class-wp-mcp-ai-incident-notifier.php`

**Phase-aware message templates:**

```
detected:      "We are investigating reports of {issue}. More information to follow."
investigating: "Investigation update: {message}"
identified:    "The issue has been identified: {message}. A fix is being prepared."
monitoring:    "A fix has been deployed. We are monitoring the results."
resolved:      "This incident has been resolved. {summary}"
```

**Channels:** Email, Outbound Webhook, Channel Broadcast (matching maintenance notifier pattern).

**Auto-incident creation (optional, configurable):**
When `wp_mcp_ai_service_status_changed` fires with `major_outage`, optionally auto-create an incident in `detected` phase. Gated by cooldown (1 per component per hour).

**Checklist:**
- [ ] Notification templates are filterable: `wp_mcp_ai_incident_notification_message`
- [ ] Cooldown prevents notification spam on rapid phase changes (1-minute minimum between notifications)
- [ ] Webhook event taxonomy: `incident.created`, `incident.updated`, `incident.resolved`

---

### Task 3.4 — Build Incident-Lesson Bridge

**File:** `addons/pro/includes/class-wp-mcp-ai-incident-lesson-bridge.php`

On `wp_mcp_ai_incident_resolved`:
1. Prompt operator (or auto-create) a `mcp_ai_lesson` post
2. Link the incident ID to the lesson via `_mcp_ai_incident_lesson_id` post meta
3. Optionally pre-fill lesson fields from incident data (timeline → "what happened", resolution → "what was done")

**Checklist:**
- [ ] Lesson creation is optional (operator can skip)
- [ ] `mcp_ai_lesson` CPT already exists and is registered by `WP_MCP_AI_Incident_Learning`
- [ ] Cross-reference is bidirectional: incident knows its lesson, lesson can reference its incident

---

### Task 3.5 — Build Admin Incidents Page

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-incidents-page.php`

- Incident list with phase filter, severity filter, date range
- Create incident form (title, severity, affected services, initial message)
- Incident detail/edit view with:
  - Phase transition buttons (Investigating → Identified → Monitoring → Resolved)
  - Timeline display (reverse chronological)
  - "Add Update" form (message + optional phase change)
  - "Resolve" button with confirmation
- Link to create lesson (post-resolution)

**Checklist:**
- [ ] Registered under `nvoos-pro-dashboard` parent slug
- [ ] Priority 30 for `admin_menu` (after Maintenance at 29)
- [ ] All form submissions nonce-protected
- [ ] `manage_options` capability gate
- [ ] Phase transition buttons contextual — only show valid next phases

---

### Task 3.6 — Build AI Tools

Four new tools following the existing tool implementation pattern:

#### `get_service_status` (Pro)
- **File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-get-service-status.php`
- **Slug:** `get_service_status`
- **Capability:** `edit_posts`
- **Parameters:** `component_slug` (optional — omit for all)
- **Returns:** Status snapshot for one or all components

#### `create_incident` (Pro)
- **File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-create-incident.php`
- **Slug:** `create_incident`
- **Capability:** `manage_options`
- **Parameters:** `title`, `severity`, `services`, `initial_message`
- **Returns:** Created incident ID and data

#### `update_incident` (Pro)
- **File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-update-incident.php`
- **Slug:** `update_incident`
- **Capability:** `manage_options`
- **Parameters:** `incident_id`, `new_phase`, `message`
- **Returns:** Updated incident data with new timeline entry

#### `resolve_incident` (Pro)
- **File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-resolve-incident.php`
- **Slug:** `resolve_incident`
- **Capability:** `manage_options`
- **Parameters:** `incident_id`, `resolution_message`
- **Returns:** Resolved incident data

#### `schedule_maintenance` (Pro)
- **File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-schedule-maintenance.php`
- **Slug:** `schedule_maintenance`
- **Capability:** `manage_options`
- **Parameters:** `title`, `message`, `start_time`, `end_time`, `services`, `notify_channels`
- **Returns:** Created maintenance window data

**Checklist (all tools):**
- [ ] Implement `WP_MCP_AI_Tool_Interface`
- [ ] Use `WP_MCP_AI_Tool_Default_Capability` trait
- [ ] Follow canonical envelope return format (success array or `WP_Error`, never `array( 'success' => false, ... )`)
- [ ] Follow two-gate sanitisation: sanitize `$arguments[...]` at entry, escape every value at exit
- [ ] `get_parameters_schema()` with JSON Schema types
- [ ] All strings translatable with `mcp-ai-wpoos` text domain
- [ ] Register in the Pro tool init file

---

## Validation

After each phase:

```bash
# Lint changed files
composer run lint:errors-only -- --filter=gitmodified

# Auto-fix style issues
composer run format

# PHP compatibility check (Phase 1 — 7.4+)
composer run lint:compat

# PHP compatibility check (Phase 2–3 — 8.1+)
# Pro addon uses its own phpcs config

# Run tests
composer run test

# Full lint (before PR)
composer run lint:errors-only

# CI verification (all gates)
composer run ci:all
```

---

## Risk & Dependency Map

```
Phase 1 (Status Page)
│
├── Depends on: Nothing new
├── Blocks: Nothing
├── Risk: Health probes timing out → mitigated by 10s timeout + try/catch
│
Phase 2 (Maintenance)
│
├── Depends on: Phase 1 (uses service components + status registry)
├── Depends on: Channel Broadcast scheduler (existing, tested)
├── Blocks: Phase 3 (incidents reference maintenance windows)
├── Risk: Banner injection conflicts with page builders → mitigated by optional shortcode placement
│
Phase 3 (Incidents)
│
├── Depends on: Phase 1 (status page shows active incidents)
├── Depends on: Phase 2 (incidents can trigger maintenance windows)
├── Depends on: Incident Learning System CPT (existing)
├── Blocks: Nothing
├── Risk: State machine bugs cause stuck incidents → mitigated by "Force Resolve" admin override
```

---

## Rollback Plan

Each phase is independently deployable and reversible:

1. **Phase 1 rollback:** Remove new files from loader.php; delete `wp_mcp_ai_service_status`, `wp_mcp_ai_service_uptime_history`, `wp_mcp_ai_last_health_check` options; clear cron hooks. Status page simply disappears — no effect on existing monitoring.
2. **Phase 2 rollback:** Delete `mcp_ai_maintenance` CPT posts (via WP admin or WP-CLI); deactivate cron; remove files. Active banners disappear. No data loss.
3. **Phase 3 rollback:** Delete `mcp_ai_incident` CPT posts; deactivate cron; remove files. Incident data is lost but operational systems unaffected.

---

## Appendix A: Status Values Reference

| Status | CSS Class | Badge Color | Description |
|---|---|---|---|
| `operational` | `ok` | Green (#d4edda / #155724) | Component is functioning normally |
| `degraded_performance` | `warning` | Yellow (#fff3cd / #856404) | Component is slow or experiencing minor issues |
| `partial_outage` | `warning` | Orange (#ffeaa7 / #b45309) | Some users or features are affected |
| `major_outage` | `error` | Red (#f8d7da / #721c24) | Component is completely unavailable |
| `under_maintenance` | `maintenance` | Blue (#cce5ff / #004085) | Scheduled maintenance in progress |

## Appendix B: Incident Severity Reference

| Severity | Description | Auto-Create Incident? |
|---|---|---|
| `minor` | Low-impact issue; non-critical functionality affected | No |
| `major` | Significant impact; core functionality degraded | Yes (with cooldown) |
| `critical` | Service outage; complete unavailability | Yes (immediate) |

## Appendix C: CPT Slug Reference

| CPT Slug | File | Phase | Public? | REST? |
|---|---|---|---|---|
| `mcp_ai_service` | `includes/class-wp-mcp-ai-service-status-cpt.php` | 1 | No | Yes |
| `mcp_ai_maintenance` | `addons/pro/includes/class-wp-mcp-ai-maintenance-cpt.php` | 2 | No | Yes |
| `mcp_ai_incident` | `addons/pro/includes/class-wp-mcp-ai-incident-cpt.php` | 3 | No | Yes |
| `mcp_ai_lesson` | `includes/class-wp-mcp-ai-incident-learning.php` | Existing | No | Yes |
