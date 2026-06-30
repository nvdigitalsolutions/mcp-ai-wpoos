# JetBooking & JetAppointment — Calendar & Places Integration: Implementation Plan

**Date:** June 30, 2026  
**Status:** 📋 PLANNING  
**Target Release:** NV oOS Pro v1.5.0  
**Reference Proposal:** [`JETBOOKING-JETAPPOINTMENT-CALENDAR-PLACES-INTEGRATION.md`](../proposals/JETBOOKING-JETAPPOINTMENT-CALENDAR-PLACES-INTEGRATION.md)  
**Estimated Stories:** 28  
**Estimated Engineering Hours:** 40–54

---

## Table of Contents

1. [Story Map](#1-story-map)
2. [Phase 1: JetAppointment Adapter (Week 1–2)](#2-phase-1-jetappointment-adapter)
3. [Phase 2: JetBooking Adapter (Week 2–3)](#3-phase-2-jetbooking-adapter)
4. [Phase 3: Enhanced Calendar Tools (Week 3–4)](#4-phase-3-enhanced-calendar-tools)
5. [Phase 4: Places ↔ Booking Bridge (Week 4–5)](#5-phase-4-places--booking-bridge)
6. [Phase 5: Settings & Administration (Week 5)](#5-phase-5-settings--administration)
7. [Phase 6: Testing & Validation (Week 5–6)](#6-phase-6-testing--validation)
8. [File Manifest](#7-file-manifest)
9. [Testing Strategy](#8-testing-strategy)
10. [Rollback Plan](#9-rollback-plan)

---

## 1. Story Map

```
Week 1          Week 2          Week 3          Week 4          Week 5          Week 6
─────┬─────────────┬─────────────┬─────────────┬─────────────┬─────────────┬─────────────▶

[P1.1]──[P1.2]──[P1.3]──[P1.4]──[P1.5]──[P1.6]           ← JetAppointment Adapter
              [P2.1]──[P2.2]──[P2.3]──[P2.4]──[P2.5]──[P2.6] ← JetBooking Adapter
                            [P3.1]──[P3.2]──[P3.3]──[P3.4]──[P3.5]──[P3.6]──[P3.7]──[P3.8] ← Enhanced Tools
                                              [P4.1]──[P4.2]──[P4.3]──[P4.4]   ← Places Bridge
                                                          [P5.1]──[P5.2]──[P5.3]──[P5.4] ← Settings & Admin
                                                                          [T1]──[T2]──[T3]──[T4] ← Testing
```

**Parallelism note:** P1 and P2 can start simultaneously since they have disjoint write sets. P3 depends on P1+P2. P4 depends on P3. P5 can start alongside P4. Testing runs continuously but formal test stories (T1–T4) run after P5.

---

## 2. Phase 1: JetAppointment Adapter

**Goal:** All NV oOS tools can discover, read, and write JetAppointment data through a single adapter class. Zero changes to existing tool files yet — the adapter exists alongside them, ready for Phase 3 wiring.

### Story 1.1 — Create Adapter Directory & Interface

**Files to create:**
- `addons/pro/includes/adapters/README.md`
- `addons/pro/includes/adapters/interface-wp-mcp-ai-booking-adapter.php`

**`README.md`** — Follows the folder README convention (purpose, public surface, neighbors, conventions). declare:

```
# Adapters

## Purpose

Booking-system adapters that let Calendar Booking and Places tools interact
with third-party booking plugins (JetAppointment, JetBooking) without
hardcoding vendor-specific logic.

## Public Surface

- `WP_MCP_AI_Booking_Adapter_Interface` — contract every adapter must satisfy
- `WP_MCP_AI_Booking_Adapter_Factory` — detection + lazy instantiation
- `WP_MCP_AI_JetAppointment_Adapter` — JetAppointment REST API bridge
- `WP_MCP_AI_JetBooking_Adapter` — JetBooking REST + DB bridge

## Conventions

- Every adapter's `is_available()` must check for plugin class, DB tables, AND
  configuration — not just `is_plugin_active()`.
- All public methods return canonical envelope (success array or WP_Error).
- Adapters cache provider/service lists in transients (5 min TTL).
- Never call adapter methods from tools that haven't checked `is_available()` first.
- Credentials must come from the Password Vault, never from raw option keys.
```

**`interface-wp-mcp-ai-booking-adapter.php`** — Define the contract:

```php
<?php
/**
 * Booking Adapter Interface
 *
 * Contract that every third-party booking system adapter must implement.
 * Enables the Calendar Booking Toolkit to interact with JetAppointment,
 * JetBooking, and future booking plugins through a consistent API surface.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Adapters
 * @since     1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface WP_MCP_AI_Booking_Adapter_Interface {

    /**
     * Check whether the external booking system is available.
     *
     * Must verify: plugin class exists, required DB tables exist,
     * and configuration is complete.
     *
     * @since 1.5.0
     * @return bool
     */
    public static function is_available() : bool;

    /**
     * Human-readable reason why the adapter is unavailable.
     *
     * @since 1.5.0
     * @return string
     */
    public static function get_unavailable_reason() : string;

    /**
     * Get a unique slug identifying this adapter.
     *
     * @since 1.5.0
     * @return string e.g. 'jetappointment', 'jetbooking'
     */
    public function get_slug() : string;

    /**
     * Get a human-readable label for this adapter.
     *
     * @since 1.5.0
     * @return string e.g. 'JetAppointment', 'JetBooking'
     */
    public function get_label() : string;

    /**
     * Get bookings/appointments matching the given filters.
     *
     * @since 1.5.0
     * @param array $filters date_from, date_to, status, provider_id, service_id
     * @param int   $limit   Max results (default 50)
     * @param int   $offset  Pagination offset (default 0)
     * @return array{success:bool, items:array, total:int}|WP_Error
     */
    public function get_bookings( array $filters = array(), int $limit = 50, int $offset = 0 );

    /**
     * Get a single booking/appointment by its ID.
     *
     * @since 1.5.0
     * @param int|string $booking_id External system's booking ID.
     * @return array{success:bool, booking:array}|WP_Error
     */
    public function get_booking( $booking_id );

    /**
     * Create a new booking/appointment.
     *
     * @since 1.5.0
     * @param array $data Booking data (fields vary by adapter).
     * @return array{success:bool, booking_id:int|string, booking:array}|WP_Error
     */
    public function create_booking( array $data );

    /**
     * Update an existing booking/appointment.
     *
     * @since 1.5.0
     * @param int|string $booking_id External system's booking ID.
     * @param array      $data       Fields to update.
     * @return array{success:bool, booking:array}|WP_Error
     */
    public function update_booking( $booking_id, array $data );

    /**
     * Cancel/delete a booking/appointment.
     *
     * @since 1.5.0
     * @param int|string $booking_id External system's booking ID.
     * @param string     $reason     Optional cancellation reason.
     * @return array{success:bool}|WP_Error
     */
    public function cancel_booking( $booking_id, string $reason = '' );

    /**
     * Check availability for a time range.
     *
     * @since 1.5.0
     * @param string $start_time  Start datetime (Y-m-d H:i:s).
     * @param string $end_time    End datetime (Y-m-d H:i:s).
     * @param array  $context     Optional: provider_id, service_id, unit_ids.
     * @return array{success:bool, available:bool, conflicts:array, reasons:array}|WP_Error
     */
    public function check_availability( string $start_time, string $end_time, array $context = array() );

    /**
     * Get available time slots for a date.
     *
     * @since 1.5.0
     * @param string $date            Date (Y-m-d).
     * @param int    $duration_minutes Required slot duration.
     * @param array  $context         Optional: provider_id, service_id.
     * @return array{success:bool, date:string, slots:array, total:int}|WP_Error
     */
    public function get_available_slots( string $date, int $duration_minutes = 60, array $context = array() );

    /**
     * Get providers/staff/resources available in this system.
     *
     * @since 1.5.0
     * @param array $filters Optional filters.
     * @return array{success:bool, providers:array, total:int}|WP_Error
     */
    public function get_providers( array $filters = array() );

    /**
     * Get services offered through this system.
     *
     * @since 1.5.0
     * @param array $filters Optional: provider_id, category.
     * @return array{success:bool, services:array, total:int}|WP_Error
     */
    public function get_services( array $filters = array() );

    /**
     * Run a health check against the external system.
     *
     * @since 1.5.0
     * @return array{success:bool, healthy:bool, checks:array, message:string}
     */
    public function health_check() : array;
}
```

### Story 1.2 — Create `WP_MCP_AI_Booking_Adapter_Factory`

**File to create:**
- `addons/pro/includes/adapters/class-wp-mcp-ai-booking-adapter-factory.php`

**Responsibilities:**
1. Detect which booking plugins are available (lazy — only when queried)
2. Instantiate adapters (lazy — only when first requested)
3. Cache adapter instances in static properties (singleton-per-adapter pattern)
4. Provide convenience methods: `has_jetappointment()`, `get_jetappointment()`, `has_jetbooking()`, `get_jetbooking()`, `get_all_available()`

```php
<?php
/**
 * Booking Adapter Factory
 *
 * Detects available third-party booking systems and provides lazy-instantiated
 * adapter instances. Tools call the factory; the factory decides which adapters
 * are available.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Adapters
 * @since     1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_MCP_AI_Booking_Adapter_Factory {

    /** @var array<string,WP_MCP_AI_Booking_Adapter_Interface> */
    private static $adapters = array();

    /** @var array<string,bool> */
    private static $availability  = array();

    /** @var array<string,string> */
    private static $availability_reasons = array();

    /** @var bool */
    private static $scanned = false;

    /**
     * Scan for available adapters (runs once).
     */
    private static function scan() : void {
        if ( self::$scanned ) {
            return;
        }
        self::$scanned = true;

        // JetAppointment detection.
        if ( class_exists( 'WP_MCP_AI_JetAppointment_Adapter' ) ) {
            if ( WP_MCP_AI_JetAppointment_Adapter::is_available() ) {
                self::$availability['jetappointment'] = true;
            } else {
                self::$availability['jetappointment']         = false;
                self::$availability_reasons['jetappointment'] = WP_MCP_AI_JetAppointment_Adapter::get_unavailable_reason();
            }
        }

        // JetBooking detection.
        if ( class_exists( 'WP_MCP_AI_JetBooking_Adapter' ) ) {
            if ( WP_MCP_AI_JetBooking_Adapter::is_available() ) {
                self::$availability['jetbooking'] = true;
            } else {
                self::$availability['jetbooking']         = false;
                self::$availability_reasons['jetbooking'] = WP_MCP_AI_JetBooking_Adapter::get_unavailable_reason();
            }
        }
    }

    public static function has_jetappointment() : bool {
        self::scan();
        return ! empty( self::$availability['jetappointment'] );
    }

    public static function has_jetbooking() : bool {
        self::scan();
        return ! empty( self::$availability['jetbooking'] );
    }

    /**
     * @return WP_MCP_AI_JetAppointment_Adapter|null
     */
    public static function get_jetappointment() {
        if ( ! self::has_jetappointment() ) {
            return null;
        }
        if ( ! isset( self::$adapters['jetappointment'] ) ) {
            self::$adapters['jetappointment'] = new WP_MCP_AI_JetAppointment_Adapter();
        }
        return self::$adapters['jetappointment'];
    }

    /**
     * @return WP_MCP_AI_JetBooking_Adapter|null
     */
    public static function get_jetbooking() {
        if ( ! self::has_jetbooking() ) {
            return null;
        }
        if ( ! isset( self::$adapters['jetbooking'] ) ) {
            self::$adapters['jetbooking'] = new WP_MCP_AI_JetBooking_Adapter();
        }
        return self::$adapters['jetbooking'];
    }

    /**
     * @return array<string,WP_MCP_AI_Booking_Adapter_Interface>
     */
    public static function get_all_available() : array {
        self::scan();
        $available = array();
        if ( self::has_jetappointment() ) {
            $available['jetappointment'] = self::get_jetappointment();
        }
        if ( self::has_jetbooking() ) {
            $available['jetbooking'] = self::get_jetbooking();
        }
        return $available;
    }

    /**
     * @return array{slug:string,label:string,available:bool,reason:string}[]
     */
    public static function get_statuses() : array {
        self::scan();
        $statuses = array();
        foreach ( array( 'jetappointment', 'jetbooking' ) as $slug ) {
            $statuses[] = array(
                'slug'      => $slug,
                'label'     => self::get_label( $slug ),
                'available' => self::$availability[ $slug ] ?? false,
                'reason'    => self::$availability_reasons[ $slug ] ?? __( 'Adapter class not loaded.', 'mcp-ai-wpoos-pro' ),
            );
        }
        return $statuses;
    }

    private static function get_label( string $slug ) : string {
        $labels = array(
            'jetappointment' => __( 'JetAppointment', 'mcp-ai-wpoos-pro' ),
            'jetbooking'     => __( 'JetBooking', 'mcp-ai-wpoos-pro' ),
        );
        return $labels[ $slug ] ?? $slug;
    }
}
```

### Story 1.3 — Create `WP_MCP_AI_JetAppointment_Adapter`

**File to create:**
- `addons/pro/includes/adapters/class-wp-mcp-ai-jetappointment-adapter.php`

**Architecture:**
- Uses WordPress HTTP API (`wp_remote_get`, `wp_remote_post`) for all REST calls
- Reads API credentials from Password Vault (`wp_mcp_ai_get_secret('jetappointment_api')`)
- Authenticates via WordPress Application Password (Basic Auth header)
- Base URL: `rest_url('jet-engine/v2/appointment-')`

**Detection logic (`is_available()`):**
```php
public static function is_available() : bool {
    global $wpdb;

    // 1. JetEngine must be active (JetAppointment depends on it).
    if ( ! function_exists( 'jet_engine' ) ) {
        return false;
    }

    // 2. JetAppointment DB table must exist.
    $table = $wpdb->prefix . 'jet_appointment';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( ! $exists ) {
        return false;
    }

    // 3. JetAppointment must be configured (settings option exists).
    $settings = get_option( 'jet_appointment_settings' );
    if ( empty( $settings ) ) {
        return false;
    }

    // 4. Integration must be enabled in NV oOS settings (auto-detect default = true).
    $nv_settings = get_option( 'wp_mcp_ai_settings', array() );
    if ( isset( $nv_settings['enable_jetappointment_integration'] )
         && empty( $nv_settings['enable_jetappointment_integration'] ) ) {
        return false;
    }

    return true;
}
```

**REST API endpoint mapping:**

| Adapter Method | REST Endpoint | Method |
|---|---|---|
| `get_bookings()` | `/jet-engine/v2/appointment-appointments-list` | GET |
| `get_booking()` | `/jet-engine/v2/appointment-get-appointment` | GET |
| `create_booking()` | `/jet-engine/v2/appointment-add-appointment` | POST |
| `update_booking()` | `/jet-engine/v2/appointment-update-appointment` | POST |
| `cancel_booking()` | `/jet-engine/v2/appointment-delete-appointment` | DELETE |
| `check_availability()` | `/jet-engine/v2/appointment-refresh-date/` | GET |
| `get_available_slots()` | `/jet-engine/v2/appointment-refresh-date/` | GET |
| `get_providers()` | Direct WP_Query on configured provider CPT | — |
| `get_services()` | Direct WP_Query on configured service CPT | — |

**Key implementation details:**

```php
/**
 * Make an authenticated request to the JetAppointment REST API.
 *
 * @param string $endpoint Relative endpoint path (e.g. 'add-appointment').
 * @param string $method   HTTP method.
 * @param array  $body     Request body (for POST).
 * @return array|WP_Error  Decoded JSON response or error.
 */
private function api_request( string $endpoint, string $method = 'GET', array $body = array() ) {
    $url = rest_url( 'jet-engine/v2/appointment-' . $endpoint );

    $args = array(
        'method'  => $method,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $this->get_api_credentials() ),
            'Content-Type'  => 'application/json',
        ),
        'timeout' => 30,
    );

    if ( ! empty( $body ) && 'GET' !== $method ) {
        $args['body'] = wp_json_encode( $body );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $code >= 400 ) {
        return new WP_Error(
            'jetappointment_api_error',
            sprintf(
                /* translators: 1: HTTP status code, 2: API error message */
                __( 'JetAppointment API returned %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
                $code,
                isset( $data['message'] ) ? $data['message'] : __( 'Unknown error', 'mcp-ai-wpoos-pro' )
            )
        );
    }

    return $data;
}

/**
 * Get API credentials from Password Vault.
 *
 * @return string "username:application_password" for Basic Auth.
 */
private function get_api_credentials() : string {
    // Try Password Vault first.
    if ( function_exists( 'wp_mcp_ai_get_secret' ) ) {
        $secret = wp_mcp_ai_get_secret( 'jetappointment_api' );
        if ( $secret ) {
            return $secret;
        }
    }

    // Fallback: legacy option (migration path).
    $legacy = get_option( 'wp_mcp_ai_jetappointment_api_credentials', '' );
    return $legacy;
}
```

**Data mapping — JetAppointment → Canonical Envelope:**

```php
public function get_bookings( array $filters = array(), int $limit = 50, int $offset = 0 ) {
    $response = $this->api_request( 'appointments-list', 'GET', array_merge( $filters, array(
        'per_page' => $limit,
        'page'     => floor( $offset / $limit ) + 1,
    ) ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $items = array();
    foreach ( $response['data'] ?? array() as $raw ) {
        $items[] = array(
            'id'               => absint( $raw['ID'] ?? 0 ),
            'service_id'       => absint( $raw['service'] ?? 0 ),
            'provider_id'      => absint( $raw['provider'] ?? 0 ),
            'date'             => sanitize_text_field( $raw['date'] ?? '' ),
            'start_time'       => sanitize_text_field( $raw['slot'] ?? '' ),
            'end_time'         => sanitize_text_field( $raw['slot_end'] ?? '' ),
            'status'           => sanitize_key( $raw['status'] ?? 'pending' ),
            'user_email'       => sanitize_email( $raw['user_email'] ?? '' ),
            'date_timestamp'   => absint( $raw['date_timestamp'] ?? 0 ),
            'slot_timestamp'   => absint( $raw['slot_timestamp'] ?? 0 ),
            'source'           => 'jetappointment',
        );
    }

    return array(
        'success' => true,
        'items'   => $items,
        'total'   => absint( $response['total'] ?? count( $items ) ),
    );
}
```

### Story 1.4 — Register Adapter Autoloading

**Files to modify:**
- `addons/pro/includes/calendar-booking-toolkit-init.php`

**Changes:**
1. Add `require_once` for the adapter interface at the top
2. Add `require_once` for the adapter factory
3. Conditionally load `WP_MCP_AI_JetAppointment_Adapter` only when the toolkit is enabled (to avoid loading unused code)

```php
// In calendar-booking-toolkit-init.php, after existing requires:

// --- Booking Adapters (lazy-loaded; factory gate-keeps availability) ---
$adapters_dir = WP_MCP_AI_PRO_PATH . 'includes/adapters/';

// Interface + factory always loaded when toolkit is enabled (tiny, no I/O).
require_once $adapters_dir . 'interface-wp-mcp-ai-booking-adapter.php';
require_once $adapters_dir . 'class-wp-mcp-ai-booking-adapter-factory.php';

// Concrete adapters: only load the class file if the plugin might be active.
// The adapter's own is_available() will do the final check.
if ( function_exists( 'jet_engine' ) ) {
    require_once $adapters_dir . 'class-wp-mcp-ai-jetappointment-adapter.php';
}

if ( class_exists( 'Jet_Booking' ) ) {
    require_once $adapters_dir . 'class-wp-mcp-ai-jetbooking-adapter.php';
}
```

### Story 1.5 — Unit Tests for JetAppointment Adapter

**File to create:**
- `addons/pro/tests/adapters/test-jetappointment-adapter.php`

**Test cases:**

| Test | What it verifies |
|---|---|
| `test_is_available_returns_false_without_jetengine()` | `function_exists('jet_engine')` returns false → adapter unavailable |
| `test_is_available_returns_false_without_table()` | JetEngine exists but `jet_appointment` table missing → unavailable |
| `test_is_available_returns_false_when_disabled_in_settings()` | `enable_jetappointment_integration` = false → unavailable |
| `test_get_bookings_returns_canonical_envelope()` | Response shape matches `{success, items, total}` |
| `test_get_bookings_maps_fields_correctly()` | Raw JA fields map to canonical field names |
| `test_create_booking_returns_wp_error_on_api_failure()` | HTTP 500 from JA → `WP_Error` returned |
| `test_check_availability_respects_provider_filter()` | Passing `provider_id` filters excluded dates correctly |
| `test_get_providers_queries_configured_cpt()` | Provider CPT is queried with correct post_type |
| `test_health_check_reports_unhealthy_when_api_unreachable()` | Bad credentials → `healthy: false` |
| `test_api_request_uses_application_password_auth()` | Authorization header contains Basic auth |

**Test setup pattern:**
```php
class Test_JetAppointment_Adapter extends WP_UnitTestCase {

    /** @var WP_MCP_AI_JetAppointment_Adapter */
    private $adapter;

    public function set_up() : void {
        parent::set_up();

        // Mock JetEngine existence.
        if ( ! function_exists( 'jet_engine' ) ) {
            // In test environment, define a stub.
        }

        // Create the jet_appointment table.
        global $wpdb;
        $table = $wpdb->prefix . 'jet_appointment';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            service bigint(20),
            provider bigint(20),
            date date,
            slot time,
            slot_end time,
            status varchar(20),
            user_email varchar(100),
            PRIMARY KEY (id)
        )" );

        // Set up JetAppointment settings.
        update_option( 'jet_appointment_settings', array(
            'provider_post_type' => 'doctor',
            'service_post_type'  => 'service',
        ) );

        // Enable integration in NV oOS settings.
        $settings = get_option( 'wp_mcp_ai_settings', array() );
        $settings['enable_jetappointment_integration'] = true;
        update_option( 'wp_mcp_ai_settings', $settings );

        $this->adapter = new WP_MCP_AI_JetAppointment_Adapter();
    }

    public function tear_down() : void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jet_appointment" );
        parent::tear_down();
    }

    // ... test methods ...
}
```

### Story 1.6 — Password Vault Integration

**Files to modify:**
- `addons/pro/includes/adapters/class-wp-mcp-ai-jetappointment-adapter.php` (credential retrieval)
- `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php` (UI for entering credentials)

**Changes:**
1. Add "JetAppointment API Credentials" section to the Calendar Booking settings page
2. Fields: WordPress username, Application Password
3. On save: store in Password Vault via `wp_mcp_ai_set_secret('jetappointment_api', 'username:password')`
4. On load: read from Password Vault, mask password in UI
5. "Test Connection" button → calls `$adapter->health_check()` and displays result

---

## 3. Phase 2: JetBooking Adapter

**Goal:** Same pattern as Phase 1, but for JetBooking's daily-booking system with unit-based inventory.

### Story 2.1 — Create `WP_MCP_AI_JetBooking_Adapter`

**File to create:**
- `addons/pro/includes/adapters/class-wp-mcp-ai-jetbooking-adapter.php`

**Key differences from JetAppointment adapter:**

| Aspect | JetAppointment | JetBooking |
|---|---|---|
| Booking unit | Hourly slot | Daily (check-in → check-out) |
| Resource model | Provider → Service | Instance → Unit |
| REST base | `/jet-engine/v2/appointment-` | `/jet-booking/v2/` |
| Availability query | `appointment-refresh-date` | `wp_jet_apartment_units_dates` table (direct DB) |
| Statuses | pending, confirmed, cancelled | on-hold, confirmed, cancelled, completed |
| WooCommerce mode | Optional | Built-in (2 modes: Plain + WC-based) |

**Detection logic (`is_available()`):**
```php
public static function is_available() : bool {
    global $wpdb;

    // 1. JetBooking plugin class must exist.
    if ( ! class_exists( 'Jet_Booking' ) ) {
        return false;
    }

    // 2. Core booking tables must exist.
    $bookings_table = $wpdb->prefix . 'jet_apartment_bookings';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings_table ) ) !== $bookings_table ) {
        return false;
    }

    // 3. JetBooking must be configured.
    $settings = get_option( 'jet_booking_settings' );
    if ( empty( $settings ) ) {
        return false;
    }

    // 4. Integration toggle in NV oOS.
    $nv_settings = get_option( 'wp_mcp_ai_settings', array() );
    if ( isset( $nv_settings['enable_jetbooking_integration'] )
         && empty( $nv_settings['enable_jetbooking_integration'] ) ) {
        return false;
    }

    return true;
}
```

**JetBooking mode detection (Plain vs WooCommerce):**
```php
/**
 * Detect JetBooking mode.
 *
 * @return string 'plain' or 'woocommerce'
 */
private function detect_mode() : string {
    $settings = get_option( 'jet_booking_settings', array() );
    $mode     = $settings['booking_mode'] ?? 'plain';

    if ( 'woocommerce_based' === $mode && class_exists( 'WooCommerce' ) ) {
        return 'woocommerce';
    }

    return 'plain';
}
```

**Unit availability (direct DB query — REST API doesn't expose this granularly):**
```php
/**
 * Check unit availability for an instance and date range.
 *
 * JetBooking stores booked dates in wp_jet_apartment_units_dates.
 * A unit is available for a date if no row exists in that table
 * for (unit_id, date) with status 'confirmed' or 'on-hold'.
 *
 * @param int    $instance_id Booking instance post ID.
 * @param string $check_in    Check-in date (Y-m-d).
 * @param string $check_out   Check-out date (Y-m-d).
 * @return array{success:bool, available_units:array, unavailable_dates:array}|WP_Error
 */
public function get_unit_availability( int $instance_id, string $check_in, string $check_out ) {
    global $wpdb;

    $units_table = $wpdb->prefix . 'jet_apartment_units';
    $dates_table = $wpdb->prefix . 'jet_apartment_units_dates';

    // Get all units for this instance.
    $units = $wpdb->get_results( $wpdb->prepare(
        "SELECT unit_id, title FROM {$units_table} WHERE post_id = %d",
        $instance_id
    ) );

    if ( empty( $units ) ) {
        return array(
            'success'           => true,
            'available_units'   => array(),
            'unavailable_dates' => array(),
            'message'           => __( 'No units configured for this booking instance.', 'mcp-ai-wpoos-pro' ),
        );
    }

    // Get booked dates in range for all units.
    $booked = $wpdb->get_results( $wpdb->prepare(
        "SELECT unit_id, date FROM {$dates_table}
         WHERE unit_id IN (" . implode( ',', array_fill( 0, count( $units ), '%d' ) ) . ")
         AND date >= %s AND date <= %s
         AND status IN ('confirmed', 'on-hold')",
        array_merge( wp_list_pluck( $units, 'unit_id' ), array( $check_in, $check_out ) )
    ) );

    // Build availability map.
    $booked_map = array();
    foreach ( $booked as $row ) {
        $booked_map[ $row->unit_id ][] = $row->date;
    }

    $available_units   = array();
    $unavailable_dates = array();

    foreach ( $units as $unit ) {
        $booked_dates = $booked_map[ $unit->unit_id ] ?? array();
        if ( empty( $booked_dates ) ) {
            $available_units[] = array(
                'unit_id' => absint( $unit->unit_id ),
                'title'   => sanitize_text_field( $unit->title ),
            );
        } else {
            $unavailable_dates[] = array(
                'unit_id'      => absint( $unit->unit_id ),
                'title'        => sanitize_text_field( $unit->title ),
                'booked_dates' => $booked_dates,
            );
        }
    }

    return array(
        'success'           => true,
        'available_units'   => $available_units,
        'unavailable_dates' => $unavailable_dates,
        'total_units'       => count( $units ),
        'available_count'   => count( $available_units ),
    );
}
```

### Story 2.2 — JetBooking REST API Client

JetBooking's REST API endpoints (from the Gist comment: `/jet-booking/v2/`):

| Endpoint | Method | Purpose |
|---|---|---|
| `/jet-booking/v2/bookings` | GET | List bookings |
| `/jet-booking/v2/bookings/{id}` | GET | Get single booking |
| `/jet-booking/v2/bookings` | POST | Create booking |
| `/jet-booking/v2/bookings/{id}` | PUT | Update booking |
| `/jet-booking/v2/bookings/{id}` | DELETE | Delete booking |

**Implementation:**

```php
/**
 * Make authenticated REST API request to JetBooking.
 *
 * @param string $endpoint e.g. 'bookings', 'bookings/123'
 * @param string $method   HTTP method.
 * @param array  $body     Request body.
 * @return array|WP_Error
 */
private function api_request( string $endpoint, string $method = 'GET', array $body = array() ) {
    $url = rest_url( 'jet-booking/v2/' . $endpoint );

    $args = array(
        'method'  => $method,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( $this->get_api_credentials() ),
            'Content-Type'  => 'application/json',
        ),
        'timeout' => 30,
    );

    if ( ! empty( $body ) && 'GET' !== $method ) {
        $args['body'] = wp_json_encode( $body );
    }

    if ( 'GET' === $method && ! empty( $body ) ) {
        $url = add_query_arg( $body, $url );
    }

    $response = wp_remote_request( $url, $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $code >= 400 ) {
        return new WP_Error(
            'jetbooking_api_error',
            sprintf(
                /* translators: 1: HTTP code, 2: error message */
                __( 'JetBooking API returned %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
                $code,
                $data['message'] ?? __( 'Unknown error', 'mcp-ai-wpoos-pro' )
            )
        );
    }

    return $data;
}
```

### Story 2.3 — JetBooking Data Mapping

**Canonical envelope mapping for JetBooking bookings:**

```php
/**
 * Map a raw JetBooking record to the canonical booking envelope.
 *
 * @param object|array $raw Raw booking data from REST or DB.
 * @return array
 */
private function map_booking_to_canonical( $raw ) : array {
    $raw = (array) $raw;

    return array(
        'id'             => absint( $raw['id'] ?? $raw['ID'] ?? 0 ),
        'instance_id'    => absint( $raw['apartment_id'] ?? $raw['booking_item'] ?? 0 ),
        'unit_id'        => absint( $raw['unit_id'] ?? 0 ),
        'unit_title'     => sanitize_text_field( $raw['unit_title'] ?? '' ),
        'check_in_date'  => sanitize_text_field( $raw['check_in_date'] ?? $raw['check_in'] ?? '' ),
        'check_out_date' => sanitize_text_field( $raw['check_out_date'] ?? $raw['check_out'] ?? '' ),
        'status'         => sanitize_key( $raw['status'] ?? 'on-hold' ),
        'guest_count'    => absint( $raw['guests'] ?? 0 ),
        'user_email'     => sanitize_email( $raw['email'] ?? $raw['user_email'] ?? '' ),
        'order_id'       => absint( $raw['order_id'] ?? 0 ),
        'price'          => floatval( $raw['price'] ?? 0 ),
        'source'         => 'jetbooking',
    );
}
```

### Story 2.4 — JetBooking Adapter Registration

**Files to modify:**
- `addons/pro/includes/calendar-booking-toolkit-init.php`

**Changes:** Add `require_once` for JetBooking adapter (already scoped behind `class_exists('Jet_Booking')` in Story 1.4).

### Story 2.5 — JetBooking Password Vault Integration

**Files to modify:**
- `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php`

**Changes:** Add "JetBooking API Credentials" section, same pattern as JetAppointment (Story 1.6).

### Story 2.6 — Unit Tests for JetBooking Adapter

**File to create:**
- `addons/pro/tests/adapters/test-jetbooking-adapter.php`

**Test cases:**

| Test | What it verifies |
|---|---|
| `test_is_available_returns_false_without_jet_booking_class()` | Class missing → unavailable |
| `test_is_available_returns_false_without_tables()` | Tables missing → unavailable |
| `test_detect_mode_returns_plain_when_not_woocommerce()` | Mode detection |
| `test_get_unit_availability_returns_units_for_date_range()` | DB query for units_dates |
| `test_get_unit_availability_marks_booked_units_unavailable()` | Booked dates excluded |
| `test_get_bookings_returns_canonical_envelope()` | Response shape |
| `test_create_booking_in_plain_mode_uses_cpt()` | CPT-based creation for plain mode |
| `test_health_check_detects_missing_table()` | Missing tables → unhealthy |

---

## 4. Phase 3: Enhanced Calendar Tools

**Goal:** Wire existing calendar booking tools to use adapters. Tools work identically when no adapter is available; they become richer when adapters are present.

### Story 3.1 — Enhance `check_availability`

**File to modify:**
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-check-availability.php`

**Changes:**

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // ... existing validation ...

    $start_time = sanitize_text_field( $arguments['start_time'] );
    $end_time   = sanitize_text_field( $arguments['end_time'] );
    $is_available = true;
    $reasons      = array();
    $per_system    = array(); // NEW: per-system availability breakdown.

    // --- Native NV oOS checks (unchanged) ---
    $conflicts = $this->check_conflicts( $start_time, $end_time );
    if ( ! empty( $conflicts ) ) {
        $is_available = false;
        $reasons[]    = sprintf(
            __( '%d existing NV oOS appointment(s) conflict.', 'mcp-ai-wpoos-pro' ),
            count( $conflicts )
        );
    }
    $per_system['nvoos'] = array(
        'available' => empty( $conflicts ),
        'conflicts' => $conflicts,
    );

    // --- JetAppointment check (NEW) ---
    if ( WP_MCP_AI_Booking_Adapter_Factory::has_jetappointment() ) {
        $ja_adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
        $ja_context = array();

        if ( ! empty( $arguments['provider_id'] ) ) {
            $ja_context['provider_id'] = absint( $arguments['provider_id'] );
        }
        if ( ! empty( $arguments['service_id'] ) ) {
            $ja_context['service_id'] = absint( $arguments['service_id'] );
        }

        $ja_result = $ja_adapter->check_availability( $start_time, $end_time, $ja_context );

        if ( is_wp_error( $ja_result ) ) {
            $per_system['jetappointment'] = array(
                'available' => null,
                'error'     => $ja_result->get_error_message(),
            );
        } else {
            $per_system['jetappointment'] = array(
                'available' => $ja_result['available'],
                'conflicts' => $ja_result['conflicts'] ?? array(),
            );

            if ( ! $ja_result['available'] ) {
                $is_available = false;
                $ja_reasons   = $ja_result['reasons'] ?? array();
                foreach ( $ja_reasons as $reason ) {
                    $reasons[] = '[JetAppointment] ' . $reason;
                }
            }
        }
    }

    // --- JetBooking check (NEW) ---
    if ( WP_MCP_AI_Booking_Adapter_Factory::has_jetbooking() ) {
        $jb_adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetbooking();
        $jb_context = array();

        if ( ! empty( $arguments['instance_id'] ) ) {
            $jb_context['instance_id'] = absint( $arguments['instance_id'] );
        }

        $jb_result = $jb_adapter->check_availability( $start_time, $end_time, $jb_context );

        if ( is_wp_error( $jb_result ) ) {
            $per_system['jetbooking'] = array(
                'available' => null,
                'error'     => $jb_result->get_error_message(),
            );
        } else {
            $per_system['jetbooking'] = array(
                'available' => $jb_result['available'],
                'conflicts' => $jb_result['conflicts'] ?? array(),
            );

            if ( ! $jb_result['available'] ) {
                $is_available = false;
                $jb_reasons   = $jb_result['reasons'] ?? array();
                foreach ( $jb_reasons as $reason ) {
                    $reasons[] = '[JetBooking] ' . $reason;
                }
            }
        }
    }

    // Existing business hours + blocked time checks remain.

    return array(
        'success'    => true,
        'available'  => $is_available,
        'start_time' => $start_time,
        'end_time'   => $end_time,
        'per_system' => $per_system,   // NEW: per-system breakdown.
        'conflicts'  => $conflicts,
        'reasons'    => $reasons,
        'message'    => $is_available
            ? __( 'Time slot is available.', 'mcp-ai-wpoos-pro' )
            : __( 'Time slot is not available.', 'mcp-ai-wpoos-pro' ),
    );
}
```

**Schema change:** Add optional parameters `provider_id`, `service_id`, `instance_id` to the parameters schema for cross-system filtering.

### Story 3.2 — Enhance `get_available_slots`

**File to modify:**
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-available-slots.php`

**Changes:** Same pattern as Story 3.1 — compute native slots, then merge JetAppointment + JetBooking slots. Use `source` field to differentiate.

```php
// Add to returned slots:
'slots' => array(
    array(
        'start_time' => '2026-06-30 14:00:00',
        'end_time'   => '2026-06-30 15:00:00',
        'available'  => true,
        'source'     => 'nvoos',  // NEW
    ),
    array(
        'start_time'  => '2026-06-30 09:00:00',
        'end_time'    => '2026-06-30 10:00:00',
        'available'   => true,
        'source'      => 'jetappointment',  // NEW
        'provider_id' => 5,
        'service_id'  => 12,
    ),
)
```

### Story 3.3 — Enhance `get_calendar_view`

**File to modify:**
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-calendar-view.php`

**Changes:** Add JetAppointment appointments and JetBooking bookings to the unified calendar view.

```php
// In execute(), after native event/project/task queries:

// --- JetAppointment appointments (NEW) ---
$ja_items = array();
if ( WP_MCP_AI_Booking_Adapter_Factory::has_jetappointment() ) {
    $adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
    $ja_result = $adapter->get_bookings( array(
        'date_from' => $start_date,
        'date_to'   => $end_date,
    ) );

    if ( ! is_wp_error( $ja_result ) ) {
        foreach ( $ja_result['items'] as $booking ) {
            $ja_items[] = array(
                'type'         => 'appointment',
                'source'       => 'jetappointment',
                'id'           => $booking['id'],
                'title'        => sprintf(
                    /* translators: 1: service name, 2: provider name */
                    __( 'Appointment: %1$s with %2$s', 'mcp-ai-wpoos-pro' ),
                    $booking['service_id'],
                    $booking['provider_id']
                ),
                'date'         => $booking['date'],
                'time'         => $booking['start_time'],
                'status'       => $booking['status'],
                'provider_id'  => $booking['provider_id'],
                'service_id'   => $booking['service_id'],
            );
        }
    }
}

// --- JetBooking bookings (NEW) ---
$jb_items = array();
if ( WP_MCP_AI_Booking_Adapter_Factory::has_jetbooking() ) {
    $adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetbooking();
    $jb_result = $adapter->get_bookings( array(
        'date_from' => $start_date,
        'date_to'   => $end_date,
    ) );

    if ( ! is_wp_error( $jb_result ) ) {
        foreach ( $jb_result['items'] as $booking ) {
            $jb_items[] = array(
                'type'        => 'booking',
                'source'      => 'jetbooking',
                'id'          => $booking['id'],
                'title'       => sprintf(
                    /* translators: %s: unit title */
                    __( 'Booking: %s', 'mcp-ai-wpoos-pro' ),
                    $booking['unit_title']
                ),
                'date'        => $booking['check_in_date'],
                'end_date'    => $booking['check_out_date'],
                'status'      => $booking['status'],
                'instance_id' => $booking['instance_id'],
                'unit_id'     => $booking['unit_id'],
            );
        }
    }
}

// Merge all sources.
$calendar_items = array_merge( $calendar_items, $ja_items, $jb_items );
```

### Story 3.4 — Enhance `create_appointment`

**File to modify:**
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-create-appointment.php`

**Changes:** Add a `target_system` parameter. When set to `jetappointment`, create in JetAppointment instead of the native CPT.

**Schema additions:**
```php
'target_system' => array(
    'type'        => 'string',
    'enum'        => array( 'nvoos', 'jetappointment', 'jetbooking' ),
    'default'     => 'nvoos',
    'description' => __( 'Which booking system to create the appointment in.', 'mcp-ai-wpoos-pro' ),
),
'provider_id' => array(
    'type'        => 'integer',
    'description' => __( 'Provider ID (required for jetappointment target).', 'mcp-ai-wpoos-pro' ),
),
'sync_to_external' => array(
    'type'        => 'boolean',
    'default'     => false,
    'description' => __( 'If creating in NV oOS, also sync to external systems.', 'mcp-ai-wpoos-pro' ),
),
```

**Execute logic:**
```php
$target = $arguments['target_system'] ?? 'nvoos';

if ( 'jetappointment' === $target ) {
    if ( ! WP_MCP_AI_Booking_Adapter_Factory::has_jetappointment() ) {
        return new WP_Error( 'jetappointment_unavailable',
            __( 'JetAppointment is not available.', 'mcp-ai-wpoos-pro' )
        );
    }

    $adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
    $result  = $adapter->create_booking( array(
        'service'          => absint( $arguments['service_id'] ?? 0 ),
        'provider'         => absint( $arguments['provider_id'] ?? 0 ),
        'date'             => gmdate( 'd/m/Y', strtotime( $start_time ) ),
        'date_timestamp'   => strtotime( $start_time ),
        'slot'             => gmdate( 'H:i', strtotime( $start_time ) ),
        'slot_end'         => gmdate( 'H:i', strtotime( $end_time ) ),
        'slot_timestamp'   => strtotime( $start_time ),
        'slot_end_timestamp' => strtotime( $end_time ),
        'status'           => 'pending',
        'user_email'       => $client_email,
    ) );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return array(
        'success'       => true,
        'message'       => __( 'Appointment created in JetAppointment.', 'mcp-ai-wpoos-pro' ),
        'appointment_id' => $result['booking_id'],
        'target_system' => 'jetappointment',
        'appointment'   => $result['booking'],
    );
}

// Fall through to native creation (unchanged)...
```

### Story 3.5 — Enhance `get_appointment_details`, `cancel_appointment`, `update_appointment`

**Files to modify:**
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-get-appointment-details.php`
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-cancel-appointment.php`
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-update-appointment.php`

**Pattern:** Each tool accepts a `source` parameter (`nvoos` default). When `jetappointment` or `jetbooking`, delegate to the appropriate adapter.

```php
// In get_appointment_details execute():
$source        = $arguments['source'] ?? 'nvoos';
$appointment_id = absint( $arguments['appointment_id'] );

if ( 'jetappointment' === $source ) {
    $adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
    if ( ! $adapter ) {
        return new WP_Error( ... );
    }
    return $adapter->get_booking( $appointment_id );
}

// Native path (unchanged)...
```

### Story 3.6 — Enhance `optimize_schedule`

**File to modify:**
- `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-optimize-schedule.php`

**Changes:** When adapters are active, the optimization algorithm considers:
- JetAppointment provider capacities (how many appointments a provider can handle per day)
- JetBooking unit availability (how many units are free)
- Cross-system conflicts (avoid scheduling an NV oOS appointment when JetAppointment has one)

**Schema addition:**
```php
'consider_external_systems' => array(
    'type'        => 'boolean',
    'default'     => true,
    'description' => __( 'Consider JetAppointment and JetBooking data when optimizing.', 'mcp-ai-wpoos-pro' ),
),
```

### Story 3.7 — Create New Sync Tools

**Files to create (in `addons/pro/includes/tools/calendar-booking/`):**

#### 3.7a — `class-wp-mcp-ai-tool-sync-from-jetappointment.php`
Slug: `sync_from_jetappointment`

Purpose: Bidirectional sync — import JetAppointment appointments as `mcp_appointment` CPT posts. Stores `_jetappointment_id` meta for idempotent re-sync.

**Logic:**
1. Query JetAppointment for appointments in date range
2. For each appointment, check if an `mcp_appointment` post with `_jetappointment_id` meta exists
3. If exists → update the existing post
4. If not → create new `mcp_appointment` post with `_status` mapped, `_start_time` / `_end_time` from JA slot times, `_client_email`, etc.
5. Return sync statistics (created, updated, skipped, errors)

#### 3.7b — `class-wp-mcp-ai-tool-sync-to-jetappointment.php`
Slug: `sync_to_jetappointment`

Reverse: Push NV oOS appointments to JetAppointment. Skips appointments already synced (tracked via `_jetappointment_id` meta).

#### 3.7c — `class-wp-mcp-ai-tool-sync-from-jetbooking.php`
Slug: `sync_from_jetbooking`

Import JetBooking bookings as `mcp_appointment` CPT posts. Maps daily bookings to all-day or multi-day appointments.

#### 3.7d — `class-wp-mcp-ai-tool-get-jetappointment-providers.php`
Slug: `get_jetappointment_providers`

Lists JetAppointment providers. Useful for AI assistants that need to know available providers before creating an appointment.

#### 3.7e — `class-wp-mcp-ai-tool-get-jetappointment-services.php`
Slug: `get_jetappointment_services`

Lists JetAppointment services, optionally filtered by provider_id.

#### 3.7f — `class-wp-mcp-ai-tool-get-jetbooking-units.php`
Slug: `get_jetbooking_units`

Lists JetBooking units for a given booking instance, including availability status.

#### 3.7g — `class-wp-mcp-ai-tool-get-jetbooking-instances.php`
Slug: `get_jetbooking_instances`

Lists JetBooking booking instances (the CPT posts that represent bookable items like apartments, rooms, cars).

### Story 3.8 — Register New Tools

**File to modify:**
- `addons/pro/includes/tools/calendar-booking/init.php`

**Changes:** Add `require_once` for each new tool file from Story 3.7. No conditional loading needed — each tool's `is_available()` gates itself.

---

## 5. Phase 4: Places ↔ Booking Bridge

**Goal:** Places know about their linked booking systems, and booking queries can be location-aware.

### Story 4.1 — Enhance `list_places` with Booking Availability Filter

**File to modify:**
- `addons/pro/includes/tools/places/class-wp-mcp-ai-tool-list-places.php`

**Schema additions:**
```php
'has_bookings_available' => array(
    'type'        => 'boolean',
    'default'     => false,
    'description' => __( 'Only return places that have bookable services/slots.', 'mcp-ai-wpoos-pro' ),
),
'booking_date' => array(
    'type'        => 'string',
    'description' => __( 'Check booking availability for this date (Y-m-d). Required if has_bookings_available is true.', 'mcp-ai-wpoos-pro' ),
),
'booking_duration_minutes' => array(
    'type'        => 'integer',
    'default'     => 60,
    'description' => __( 'Required booking duration in minutes.', 'mcp-ai-wpoos-pro' ),
),
```

**Logic:**
1. Query places normally (existing behavior)
2. If `has_bookings_available` is true, post-filter each place:
   - Check if place has linked `mcp_service` posts with availability
   - Check if place is linked to JetAppointment providers or JetBooking instances
   - For each, call adapter's `get_available_slots()` / `get_unit_availability()`
   - Keep place in results only if at least one booking source has availability
3. Annotate each place with `booking_sources` array summarizing what's available

```php
// In get_place_data(), add:
$place_data['booking_sources'] = $this->get_booking_sources_for_place( $place_id, $arguments );
```

### Story 4.2 — Create `find_bookable_places` Tool

**File to create:**
- `addons/pro/includes/tools/places/class-wp-mcp-ai-tool-find-bookable-places.php`

Slug: `find_bookable_places`

**Purpose:** Geospatial search that only returns places with available bookings. Combines the radius-search logic from `list_places` with the availability checks from Phase 4.1.

**Schema:**
```php
'latitude'       => array( 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ),
'longitude'      => array( 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ),
'radius_km'      => array( 'type' => 'number', 'default' => 10, 'minimum' => 0.1, 'maximum' => 100 ),
'date'           => array( 'type' => 'string', 'description' => 'Date to check (Y-m-d)' ),
'duration_minutes' => array( 'type' => 'integer', 'default' => 60 ),
'place_type'     => array( 'type' => 'string' ),
'min_rating'     => array( 'type' => 'number', 'minimum' => 0, 'maximum' => 5 ),
'limit'          => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
```

**Response:**
```php
array(
    'success'  => true,
    'count'    => 3,
    'location' => array( 'latitude' => 40.7128, 'longitude' => -74.0060 ),
    'places'   => array(
        array(
            'id'               => 42,
            'name'             => 'Dr. Smith Dental',
            'distance_km'      => 1.3,
            'booking_sources'  => array(
                array(
                    'source'          => 'jetappointment',
                    'available_slots' => 5,
                    'next_slot'       => '2026-07-01 09:00:00',
                    'providers'       => array( /* ... */ ),
                ),
            ),
        ),
        // ...
    ),
)
```

### Story 4.3 — Enhance `get_place` with Linked Booking Data

**File to modify:**
- `addons/pro/includes/tools/places/class-wp-mcp-ai-tool-get-place.php`

**Changes:** When retrieving a single place, include:
- Linked `mcp_service` posts (already possible via `place_id` on services)
- JetAppointment providers at this location
- JetBooking instances at this location
- Upcoming availability summary

```php
// In execute(), after native place data:
$place_data['linked_services']     = $this->get_linked_nvoos_services( $place_id );
$place_data['jetappointment_info'] = $this->get_linked_jetappointment_data( $place_id );
$place_data['jetbooking_info']     = $this->get_linked_jetbooking_data( $place_id );
```

### Story 4.4 — Add Place-to-Provider/Instance Linkage Meta

**Files to modify:**
- `addons/pro/includes/class-wp-mcp-ai-place-cpt.php` (add metabox fields)
- `addons/pro/includes/metaboxes/places/class-wp-mcp-ai-place-metabox-details.php`

**New meta fields on Place CPT:**
```php
'_place_jetappointment_provider_ids' => array(), // Array of provider IDs at this place
'_place_jetbooking_instance_ids'     => array(), // Array of booking instance IDs at this place
```

**Admin UI:** In the Place metabox, add dropdowns populated by the adapters (if available) to link JetAppointment providers and JetBooking instances to the place.

---

## 6. Phase 5: Settings & Administration

### Story 5.1 — Add Integration Toggle Settings

**Files to modify:**
- `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php`

**New settings fields:**
```php
'enable_jetappointment_integration' => array(
    'type'    => 'checkbox',
    'label'   => __( 'Enable JetAppointment Integration', 'mcp-ai-wpoos-pro' ),
    'default' => true,
    'description' => __( 'Automatically detected. Disable to prevent NV oOS from accessing JetAppointment data.', 'mcp-ai-wpoos-pro' ),
),
'enable_jetbooking_integration' => array(
    'type'    => 'checkbox',
    'label'   => __( 'Enable JetBooking Integration', 'mcp-ai-wpoos-pro' ),
    'default' => true,
    'description' => __( 'Automatically detected. Disable to prevent NV oOS from accessing JetBooking data.', 'mcp-ai-wpoos-pro' ),
),
```

### Story 5.2 — Build Adapter Health Dashboard

**Files to modify:**
- `addons/pro/includes/admin/class-wp-mcp-ai-calendar-booking-settings-page.php`

**New UI section:** "Booking Adapter Status" with cards for each adapter:

```
┌─────────────────────────────────┐  ┌─────────────────────────────────┐
│ JetAppointment                  │  │ JetBooking                      │
│ Status: ✅ Connected            │  │ Status: ⚠️ Degraded             │
│                                  │  │                                  │
│ API: jet-engine/v2/appointment- │  │ API: jet-booking/v2/            │
│ Tables: OK (jet_appointment)    │  │ Tables: OK (jet_apartment_*)    │
│ Auth: OK                        │  │ Auth: OK                        │
│ Providers: 12                   │  │ Instances: 8                    │
│ Services: 34                    │  │ Units: 45                       │
│                                  │  │                                  │
│ [Test Connection] [Refresh]     │  │ [Test Connection] [Refresh]     │
└─────────────────────────────────┘  └─────────────────────────────────┘
```

**Health check indicators:**
- 🟢 Green: All checks pass, API reachable
- 🟡 Yellow: API reachable but tables/configuration issues
- 🔴 Red: Adapter unavailable (plugin missing, tables missing)
- ⚪ Gray: Feature disabled via integration toggle

### Story 5.3 — Add WP-CLI Commands

**Files to create:**
- `addons/pro/includes/cli/class-wp-mcp-ai-pro-cli-booking-adapters-command.php`

**Commands:**

```bash
# List all adapters and their status
wp nvoos booking adapters list

# Check adapter health
wp nvoos booking adapters health jetappointment

# Sync NV oOS appointments → JetAppointment
wp nvoos booking sync to-jetappointment --date-from=2026-07-01 --date-to=2026-07-31

# Sync JetAppointment → NV oOS
wp nvoos booking sync from-jetappointment --date-from=2026-07-01

# Import JetBooking units as NV oOS services
wp nvoos booking import jetbooking-units --instance-id=42
```

### Story 5.4 — Add Sync Cron Job (Optional Background Sync)

**Files to create:**
- `addons/pro/includes/calendar-booking/class-wp-mcp-ai-booking-sync-cron.php`

**Logic:**
1. Register a recurring cron event (`wp_mcp_ai_booking_sync`) with configurable interval (default: 15 minutes)
2. On each tick, sync recent (last 24h) JetAppointment appointments → NV oOS
3. On each tick, sync recent (last 24h) JetBooking bookings → NV oOS
4. Log sync statistics to a dedicated option for admin dashboard display
5. Gate behind `enable_jetappointment_integration` / `enable_jetbooking_integration` settings

---

## 7. File Manifest

### New Files (20 total)

```
addons/pro/includes/adapters/
├── README.md                                          ← Story 1.1
├── interface-wp-mcp-ai-booking-adapter.php             ← Story 1.1
├── class-wp-mcp-ai-booking-adapter-factory.php         ← Story 1.2
├── class-wp-mcp-ai-jetappointment-adapter.php          ← Story 1.3
└── class-wp-mcp-ai-jetbooking-adapter.php              ← Story 2.1

addons/pro/includes/tools/calendar-booking/
├── class-wp-mcp-ai-tool-sync-from-jetappointment.php   ← Story 3.7a
├── class-wp-mcp-ai-tool-sync-to-jetappointment.php     ← Story 3.7b
├── class-wp-mcp-ai-tool-sync-from-jetbooking.php       ← Story 3.7c
├── class-wp-mcp-ai-tool-get-jetappointment-providers.php ← Story 3.7d
├── class-wp-mcp-ai-tool-get-jetappointment-services.php  ← Story 3.7e
├── class-wp-mcp-ai-tool-get-jetbooking-units.php       ← Story 3.7f
└── class-wp-mcp-ai-tool-get-jetbooking-instances.php   ← Story 3.7g

addons/pro/includes/tools/places/
└── class-wp-mcp-ai-tool-find-bookable-places.php       ← Story 4.2

addons/pro/includes/calendar-booking/
└── class-wp-mcp-ai-booking-sync-cron.php               ← Story 5.4

addons/pro/includes/cli/
└── class-wp-mcp-ai-pro-cli-booking-adapters-command.php ← Story 5.3

addons/pro/tests/adapters/
├── test-jetappointment-adapter.php                     ← Story 1.5
└── test-jetbooking-adapter.php                         ← Story 2.6

addons/pro/tests/tools/calendar-booking/
├── test-sync-from-jetappointment.php                   ← Phase 6
├── test-find-bookable-places.php                       ← Phase 6
└── test-enhanced-check-availability.php                ← Phase 6
```

### Modified Files (13 total)

```
addons/pro/includes/calendar-booking-toolkit-init.php    ← Stories 1.4, 2.4
addons/pro/includes/tools/calendar-booking/init.php     ← Story 3.8
addons/pro/includes/tools/calendar-booking/
├── class-wp-mcp-ai-tool-check-availability.php         ← Story 3.1
├── class-wp-mcp-ai-tool-get-available-slots.php        ← Story 3.2
├── class-wp-mcp-ai-tool-get-calendar-view.php          ← Story 3.3
├── class-wp-mcp-ai-tool-create-appointment.php         ← Story 3.4
├── class-wp-mcp-ai-tool-get-appointment-details.php    ← Story 3.5
├── class-wp-mcp-ai-tool-cancel-appointment.php         ← Story 3.5
├── class-wp-mcp-ai-tool-update-appointment.php         ← Story 3.5
└── class-wp-mcp-ai-tool-optimize-schedule.php          ← Story 3.6
addons/pro/includes/tools/places/
├── class-wp-mcp-ai-tool-list-places.php                ← Story 4.1
└── class-wp-mcp-ai-tool-get-place.php                  ← Story 4.3
addons/pro/includes/class-wp-mcp-ai-place-cpt.php       ← Story 4.4
addons/pro/includes/admin/
└── class-wp-mcp-ai-calendar-booking-settings-page.php  ← Stories 5.1, 5.2
addons/pro/includes/metaboxes/places/
└── class-wp-mcp-ai-place-metabox-details.php           ← Story 4.4
```

---

## 8. Testing Strategy

### 8.1 Unit Tests (PHPUnit)

| Test File | Stories Covered | Key Assertions |
|---|---|---|
| `test-jetappointment-adapter.php` | 1.3, 1.5 | `is_available()`, REST response mapping, error handling, health check |
| `test-jetbooking-adapter.php` | 2.1, 2.6 | `is_available()`, mode detection, unit availability queries, booking mapping |
| `test-booking-adapter-factory.php` | 1.2 | Factory returns correct adapters, caching behavior, status reporting |
| `test-enhanced-check-availability.php` | 3.1 | Multi-system availability, per-system breakdown, backward compat |
| `test-enhanced-get-available-slots.php` | 3.2 | Merged slots from multiple sources, source tagging |
| `test-enhanced-create-appointment.php` | 3.4 | `target_system` routing, sync_to_external flag |
| `test-sync-from-jetappointment.php` | 3.7a | Idempotent import, meta foreign key tracking, status mapping |
| `test-find-bookable-places.php` | 4.2 | Radius + availability filtering, distance calculation correctness |

### 8.2 Integration Tests

| Test Scenario | Setup | Verification |
|---|---|---|
| All tools work without Jet plugins | Base mode, no JetEngine | All 20 calendar tools + 9 places tools pass existing tests (zero regression) |
| JetAppointment adapter detects correctly | JetEngine active, JA tables present | `has_jetappointment()` = true, health check passes |
| JetBooking adapter detects correctly | Jet_Booking class present, tables exist | `has_jetbooking()` = true, mode detection works |
| Cross-system availability check | NV oOS + JA appointments at same time | Both systems report conflict |
| Bidirectional sync | Create in NV oOS → sync to JA → sync from JA | No duplicates, `_jetappointment_id` correctly tracked |
| Place with linked JA provider | Place has `_place_jetappointment_provider_ids` meta | `get_place` returns `jetappointment_info` with provider details |

### 8.3 Manual QA Checklist

1. Enable Calendar Booking toolkit, Places toolkit, JetAppointment, JetBooking
2. Configure API credentials in settings
3. Verify adapter health dashboard shows green for both
4. Run `wp nvoos booking adapters list` — both adapters appear
5. Ask AI assistant: "Show me all appointments for next week" — includes JA appointments
6. Ask AI assistant: "Is Dr. Smith available Tuesday at 2pm?" — checks JA provider availability
7. Ask AI assistant: "Find restaurants within 5km that have a table for 4 at 7pm tonight" — uses `find_bookable_places`
8. Create an appointment in JA admin → run sync → verify it appears in NV oOS appointments
9. Create an appointment via AI tool with `target_system: jetappointment` → verify in JA admin

---

## 9. Rollback Plan

If integration causes issues in production:

1. **Disable per-adapter** (no code deploy needed):
   - Uncheck `enable_jetappointment_integration` in settings → adapter returns `is_available() = false` → all tools fall back to native-only behavior
   - Uncheck `enable_jetbooking_integration` → same

2. **Disable all adapters** (no code deploy needed):
   - Set `define('WP_MCP_AI_DISABLE_BOOKING_ADAPTERS', true)` in wp-config.php → factory returns empty for all adapters

3. **Remove adapter files** (code deploy):
   - Delete `addons/pro/includes/adapters/` directory
   - Revert changes to `calendar-booking-toolkit-init.php` (remove adapter requires)
   - Tools will still have adapter-gated code paths but they'll never execute (factory returns null)

4. **Full rollback** (code deploy):
   - Revert all 13 modified files to pre-implementation state
   - Delete all 20 new files
   - No database migrations needed (adapters are read-only or write to external systems)
   - The only NV oOS data created would be `_jetappointment_id` / `_jetbooking_id` meta on `mcp_appointment` posts, which can be safely ignored if adapters are removed

---

## Appendix A: JetAppointment REST API Reference

| Endpoint | Method | Parameters | Response |
|---|---|---|---|
| `/wp-json/jet-engine/v2/appointment-refresh-date/` | GET | `service={id}`, `provider={id}` | `{success, data: {excludedDates, worksDates, availableWeekDays}}` |
| `/wp-json/jet-engine/v2/appointment-add-appointment` | POST | Array of `{service, date, date_timestamp, slot, slot_end, slot_timestamp, slot_end_timestamp, status, user_email}` | `{success, data: [...]}` |
| `/wp-json/jet-engine/v2/appointment-appointments-list` | GET | `per_page`, `page`, filters | `{success, data: [...], total}` |
| `/wp-json/jet-engine/v2/appointment-get-appointment` | GET | `id` | `{success, data: {...}}` |
| `/wp-json/jet-engine/v2/appointment-update-appointment` | POST | `id`, fields to update | `{success, data: {...}}` |
| `/wp-json/jet-engine/v2/appointment-delete-appointment` | DELETE | `id` | `{success}` |

Auth: Basic Auth with WordPress Application Password (user must have `manage_options` capability).

## Appendix B: JetBooking Database Tables

| Table | Purpose | Key Columns |
|---|---|---|
| `wp_jet_apartment_bookings` | Booking records | `id`, `apartment_id`, `unit_id`, `check_in_date`, `check_out_date`, `status`, `order_id` |
| `wp_jet_apartment_units` | Individual rentable units | `unit_id`, `post_id` (instance), `title` |
| `wp_jet_apartment_units_dates` | Booked date ranges per unit | `unit_id`, `date`, `status`, `booking_id` |

## Appendix C: JetAppointment Status Mapping

| JetAppointment Status | NV oOS Status |
|---|---|
| `pending` | `pending` |
| `confirmed` | `confirmed` |
| `cancelled` | `cancelled` |
| `completed` | `completed` |

## Appendix D: JetBooking Status Mapping

| JetBooking Status | NV oOS Status |
|---|---|
| `on-hold` | `pending` |
| `confirmed` | `confirmed` |
| `cancelled` | `cancelled` |
| `completed` | `completed` |
