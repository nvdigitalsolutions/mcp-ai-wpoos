# Schedule Anything SaaS — Comprehensive Implementation Plan

> **Status:** Implementation Plan · **Target:** Phase 1 MVP in 16 weeks · **Platform:** NV oOS headless WordPress + React SPA
>
> **Last Updated:** June 9, 2026
> **Author:** NV Digital Solutions
>
> **Based on:** Industry research from production WordPress Multisite SaaS (SampleHQ), Calendly system design patterns, Cloudflare for SaaS reference architecture, Action Scheduler performance docs, Stripe billing best practices, and the existing NV oOS codebase.

---

## Table of Contents

1. [Architecture Decisions](#1-architecture-decisions)
2. [Repository Structure](#2-repository-structure)
3. [Multi-Tenancy Implementation](#3-multi-tenancy-implementation)
4. [Availability & Booking Engine](#4-availability--booking-engine)
5. [Schedule Execution Engine](#5-schedule-execution-engine)
6. [React SPA Implementation](#6-react-spa-implementation)
7. [Billing & Subscription System](#7-billing--subscription-system)
8. [Security Architecture](#8-security-architecture)
9. [Database & Caching Strategy](#9-database--caching-strategy)
10. [Observability & Operations](#10-observability--operations)
11. [Week-by-Week Build Plan](#11-week-by-week-build-plan)
12. [Testing Strategy](#12-testing-strategy)

---

## 1. Architecture Decisions

### 1.1 Decision Record

| # | Decision | Alternatives Considered | Rationale | Industry Source |
|---|---|---|---|---|
| D1 | WordPress Multisite for multi-tenancy | Shared-DB-with-tenant_id, per-instance WP, custom platform | Structural data isolation without tenant_id columns. `wpmu_create_blog()` is instant. One plugin update serves all tenants. | [SampleHQ](https://bojanjosifoski.com/multi-tenant-saas-architecture-wordpress/): "WordPress Multisite provides the tenancy primitive for free." |
| D2 | Subdomain-based tenant routing via Cloudflare Worker | Path-based, custom domain mapping, WP domain mapping | Subdomains are the industry standard for SaaS. Cloudflare Worker gives edge routing, rate limiting, and KV-backed mapping with zero cold starts. | [Cloudflare for SaaS](https://developers.cloudflare.com/reference-architecture/design-guides/leveraging-cloudflare-for-your-saas-applications/) |
| D3 | Action Scheduler for schedule execution (post-MVP) | WP-Cron, custom queue, Cloudflare Queues | WP-Cron for MVP (already integrated). Action Scheduler at 500+ tenants — same queue as WooCommerce. WP-CLI runner for high throughput. | [Action Scheduler perf docs](https://actionscheduler.org/perf/) |
| D4 | Redis SET NX for booking slot holds | Database row locks, application-level mutex | Calendly pattern: atomic slot hold with 5-min TTL. `SET slot_hold:{host}:{time} {session} EX 300 NX`. Auto-releases abandoned holds. | [Calendly system design](https://www.techinterview.org/post/3233474486/) |
| D5 | Dual-write: CPT + custom appointment table | CPT-only, custom-table-only | CPTs for admin UI (editor, metaboxes). Custom `wp_mcp_appointments` table with typed columns + indexes for availability queries. | Industry standard for WP SaaS at scale |
| D6 | BYOK default, NV Cloud opt-in for AI | NV Cloud mandatory, BYOK-only | Zero cost exposure. Managed AI is opt-in with pre-paid wallet. 7% markup covers OpenRouter costs + profit. | Standard SaaS pass-through pattern |
| D7 | React + Vite SPA (not Next.js) | Next.js SSR, Remix | Matches existing Chat SPA, Toolkit Shell, Cloudways Dashboard patterns. Static hosting on Cloudflare Pages (free). No SSR needed — all data from WP REST. | Monorepo convention alignment |
| D8 | React Flow for visual workflow builder | xyflow, custom canvas, n8n fork | MIT license. Used by n8n, Retool, and hundreds of workflow editors. Custom nodes, minimap, snap-to-grid built in. | Industry standard for workflow editors |
| D9 | Stripe Checkout + Customer Portal for billing | Custom payment UI, PayPal, Paddle | Stripe handles PCI, tax (Stripe Tax), invoicing, and customer self-service portal. Single integration. | [Stripe billing docs](https://docs.stripe.com/billing/subscriptions/build-subscriptions) |
| D10 | Must-use plugin for cross-tenant session security | App-level checks, REST auth only | Prevents the most common cross-tenant access vector in multisite: authenticated user on Site A reaching Site B. Validates `is_user_member_of_blog()` on every request. | [SampleHQ]: "prevents the most common cross-tenant access vector" |

### 1.2 Technology Stack

| Layer | Technology | Purpose |
|---|---|---|
| **Tenant Runtime** | WordPress 6.0+ Multisite + NV oOS Base + Pro | Per-tenant application server |
| **Headless API** | WP REST API (`mcp-ai/v1`, `mcp-ai-pro/v1`) | All data flows through REST |
| **Edge Routing** | Cloudflare Worker (Hono) | Subdomain→tenant routing, rate limiting |
| **Billing Backend** | Cloudflare Worker (Hono) + D1 + Stripe | Subscription management, usage metering |
| **Job Queue** | WP-Cron (MVP) → Action Scheduler (scale) | Schedule execution, async provisioning |
| **Cache** | Redis (Cloudways) + WP 6.9 salted query cache | Slot availability, REST responses |
| **Frontend** | React 18 + Vite + TypeScript + Tailwind CSS | Tenant admin + public booking SPA |
| **Workflow Editor** | React Flow (MIT) | Visual DAG schedule builder |
| **Data Fetching** | @tanstack/react-query | REST client with caching, polling, mutations |
| **Auth (SPA→WP)** | @wordpress/api-fetch + X-WP-Nonce | WordPress nonce authentication |
| **Auth (Cross-domain)** | One-time login tokens (5-min TTL) | Cross-domain SSO for subdomain workspaces |
| **CI/CD** | GitHub Actions | Lint → Test → Build → Deploy |
| **Monitoring** | Sentry + Cloudflare Analytics | Error tracking, performance |

---

## 2. Repository Structure

```
mcp-ai-wpoos/
│
├── addons/
│   │
│   ├── schedule-anything-spa/              # React SPA (follows chat-spa pattern)
│   │   ├── src/
│   │   │   ├── api/                        # REST client layer
│   │   │   │   ├── client.ts              # WP API fetch wrapper + nonce auth
│   │   │   │   ├── schedules.ts           # Schedule CRUD
│   │   │   │   ├── presets.ts             # Preset library API
│   │   │   │   ├── toolkits.ts            # Toolkit metadata + feature flags
│   │   │   │   ├── bookings.ts            # Public booking endpoints
│   │   │   │   └── billing.ts             # Stripe subscription API
│   │   │   ├── components/
│   │   │   │   ├── layout/                # AppShell, Sidebar, Topbar
│   │   │   │   ├── builder/               # Visual workflow editor (React Flow)
│   │   │   │   │   ├── FlowCanvas.tsx     # React Flow wrapper
│   │   │   │   │   ├── ToolNode.tsx       # Custom node: tool execution
│   │   │   │   │   ├── TriggerNode.tsx    # Custom node: cron/webhook trigger
│   │   │   │   │   ├── PropertyPanel.tsx  # Right sidebar: node config
│   │   │   │   │   └── ToolPalette.tsx    # Left sidebar: tools by toolkit
│   │   │   │   ├── presets/               # PresetBrowser, PresetCard, PresetDetail
│   │   │   │   ├── bookings/              # BookingFlow state machine components
│   │   │   │   │   ├── ServicePicker.tsx
│   │   │   │   │   ├── StaffPicker.tsx
│   │   │   │   │   ├── SlotPicker.tsx     # Calendar grid with available slots
│   │   │   │   │   ├── BookingForm.tsx
│   │   │   │   │   └── PaymentStep.tsx
│   │   │   │   ├── analytics/             # UsageChart, StatsCard, BillingSummary
│   │   │   │   └── shared/                # Skeleton, ErrorBoundary, EmptyState
│   │   │   ├── hooks/
│   │   │   │   ├── useTenant.ts           # Tenant context (slug, tier, features)
│   │   │   │   ├── useSchedules.ts        # react-query CRUD hooks
│   │   │   │   ├── usePresets.ts          # react-query preset hooks
│   │   │   │   ├── useBooking.ts          # State machine for booking flow
│   │   │   │   └── useAvailability.ts     # Polling hook for slot re-validation
│   │   │   ├── pages/
│   │   │   │   ├── DashboardPage.tsx      # Tenant overview + execution stats
│   │   │   │   ├── SchedulesPage.tsx      # Schedule list + create/delete/toggle
│   │   │   │   ├── BuilderPage.tsx        # Full-screen React Flow editor
│   │   │   │   ├── PresetsPage.tsx        # Preset library with search/filter
│   │   │   │   ├── HistoryPage.tsx        # Run history with expandable envelopes
│   │   │   │   ├── BookingPage.tsx        # Public booking portal (no auth)
│   │   │   │   ├── AnalyticsPage.tsx      # Usage metrics + charts
│   │   │   │   ├── SettingsPage.tsx       # Toolkit toggles, AI provider config
│   │   │   │   └── BillingPage.tsx        # Stripe Customer Portal redirect
│   │   │   ├── contexts/
│   │   │   │   ├── AuthContext.tsx         # WP nonce management + user state
│   │   │   │   └── TenantContext.tsx       # Tenant config from subdomain
│   │   │   ├── App.tsx                    # React Router + ErrorBoundary
│   │   │   └── index.tsx                  # DOM mount
│   │   ├── assets/dist/                   # Built IIFE bundle
│   │   ├── esbuild.config.cjs             # IIFE bundle config
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   └── vitest.config.ts
│   │
│   ├── schedule-anything-platform/         # WordPress companion plugin
│   │   ├── schedule-anything-platform.php  # Plugin entry
│   │   ├── uninstall.php                  # Cleanup
│   │   ├── includes/
│   │   │   ├── class-sa-plugin.php                     # Core singleton
│   │   │   ├── class-sa-tenant-controller.php          # Tenant CRUD REST
│   │   │   ├── class-sa-multisite-provisioner.php      # wpmu_create_blog() + seeding
│   │   │   ├── class-sa-toolkit-manager.php             # Per-blog feature flags
│   │   │   ├── class-sa-usage-tracker.php               # Heartbeat → Cloud Worker
│   │   │   ├── class-sa-preset-extensions.php           # 25+ additional presets
│   │   │   └── class-sa-cross-tenant-security.php       # Must-use: session validation
│   │   ├── rest/
│   │   │   └── class-sa-rest-controller.php             # /nvoos-saas/v1/tenants
│   │   └── tests/
│   │       ├── test-tenant-provisioning.php
│   │       ├── test-tenant-isolation.php
│   │       └── test-preset-extensions.php
│   │
│   ├── tenant-router/                      # Cloudflare Worker: tenant routing
│   │   ├── src/
│   │   │   ├── index.ts                   # Hono router entry
│   │   │   ├── routing.ts                 # KV-backed tenant→origin
│   │   │   ├── ratelimit.ts               # Per-tenant rate limiting
│   │   │   └── types.ts                   # Env bindings
│   │   ├── wrangler.toml
│   │   ├── package.json
│   │   └── tsconfig.json
│   │
│   ├── cloud-worker/                       # EXTENDED: SaaS billing backend
│   │   ├── src/
│   │   │   ├── index.ts                   # Add /tenants, /subscriptions routes
│   │   │   ├── tenants.ts                 # NEW: tenant CRUD
│   │   │   ├── subscriptions.ts           # NEW: Stripe subscription webhooks
│   │   │   ├── usage.ts                   # NEW: usage metering
│   │   │   ├── auth.ts                    # EXTEND: tenant-token middleware
│   │   │   └── billing.ts                 # EXTEND: subscription billing math
│   │   └── schema.sql                     # EXTEND: tenants, tenant_usage tables
│   │
│   └── pro/                                # MODIFIED: toolkit enhancements
│       └── includes/
│           ├── class-wp-mcp-ai-pro-schedule-manager.php      # ADD: batch dispatch
│           ├── class-wp-mcp-ai-pro-schedule-presets.php      # ADD: 25+ new presets
│           ├── calendar-booking/
│           │   └── class-wp-mcp-ai-appointment-repository.php # NEW: custom table
│           └── rest/
│               ├── class-wp-mcp-ai-booking-public-controller.php  # NEW: public booking
│               └── class-wp-mcp-ai-pro-schedule-rest-controller.php # NEW: schedule CRUD
│
└── docs/
    └── projects/
        ├── proposals/
        │   └── schedule-anything-saas-proposal.md     # Business proposal
        └── plans/
            └── schedule-anything-saas-implementation.md # THIS DOCUMENT
```

---

## 3. Multi-Tenancy Implementation

### 3.1 Tenant Lifecycle

```
Signup                    Provisioning                Active                    Offboarding
  │                         │                          │                          │
  ▼                         ▼                          ▼                          ▼
┌──────┐   Stripe    ┌────────────┐  wpmu_create  ┌──────────┐  subscription  ┌──────────┐
│Visitor│───────────▶│ Provisioner│──────────────▶│  Tenant  │───────────────▶│  Archive │
│signs │ Checkout    │  Worker    │   _blog()     │  Active  │    cancelled   │  + Delete│
│  up  │             │            │               │          │               │          │
└──────┘             └────────────┘               └──────────┘               └──────────┘
                         │                          │
                         │ 1. Create subsite        │ 1. Toolkit flags set
                         │ 2. Network-activate      │ 2. Presets installed
                         │    Base+Pro              │ 3. Default AI assistant
                         │ 3. Seed config           │ 4. Tenant KV record
                         │ 4. Create admin user     │ 5. Welcome email sent
                         │ 5. Send welcome          │
```

### 3.2 Subdomain Generation (Collision-Handling)

```php
function sa_generate_subdomain( string $input ): string {
    // 1. Normalize: lowercase, replace non-alphanumeric with hyphens
    $base = sanitize_title( $input );

    // 2. Strip noise words
    $noise = array( 'llc', 'inc', 'corp', 'ltd', 'company', 'the' );
    $parts = array_diff( explode( '-', $base ), $noise );
    $base  = implode( '-', $parts ) ?: 'workspace';

    // 3. Truncate to 30 chars
    $base = substr( $base, 0, 30 );

    // 4. Check uniqueness, append suffix on collision
    $candidate = $base;
    $suffix    = 1;
    while ( get_blog_id_from_url( $candidate ) || sa_tenant_exists_in_d1( $candidate ) ) {
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }

    return $candidate;
}
```

### 3.3 Tenant Provisioning (Multisite)

```php
class SA_Multisite_Provisioner {

    public function provision( array $tenant_data ): array|WP_Error {
        $slug = sa_generate_subdomain( $tenant_data['slug'] );

        // Create subsite (instant — no Cloudways API call)
        $blog_id = wpmu_create_blog(
            $this->get_multisite_domain(),
            '/' . $slug . '/',
            $tenant_data['admin_name'] . ' Workspace',
            $tenant_data['admin_email'],
            array( 'public' => 1 )
        );

        if ( is_wp_error( $blog_id ) ) {
            return $blog_id;
        }

        switch_to_blog( $blog_id );

        // Seed configuration
        $this->seed_toolkit_flags( $tenant_data['tier'] );
        $this->seed_presets( $tenant_data['tier'] );
        $this->create_default_assistant( $tenant_data['admin_name'] );

        // Elevate user to admin on this subsite
        $user = get_user_by( 'email', $tenant_data['admin_email'] );
        if ( $user ) {
            add_user_to_blog( $blog_id, $user->ID, 'administrator' );
        }

        // Generate one-time login token (5-min TTL)
        $login_token = wp_generate_password( 32, false );
        set_transient( 'sa_login_token_' . $login_token, $blog_id, 5 * MINUTE_IN_SECONDS );

        restore_current_blog();

        // Register in Cloud Worker D1 + KV
        $this->register_in_d1( $tenant_data['stripe_customer_id'], $slug, $blog_id );

        return array(
            'blog_id'     => $blog_id,
            'site_url'    => get_site_url( $blog_id ),
            'subdomain'   => $slug,
            'login_token' => $login_token,
        );
    }
}
```

### 3.4 Cross-Tenant Session Security (Must-Use Plugin)

```php
/**
 * Must-use plugin: prevents cross-tenant access.
 * Pattern from SampleHQ production: if a user on Site A
 * somehow reaches Site B, redirect them.
 */
class SA_Cross_Tenant_Security {

    public static function init() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return;
        }
        add_action( 'init', array( __CLASS__, 'validate_session' ), 1 );
    }

    public static function validate_session() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $user_id      = get_current_user_id();
        $current_blog = get_current_blog_id();

        if ( ! is_user_member_of_blog( $user_id, $current_blog ) ) {
            // User does not belong to this tenant — redirect to their home
            $user_blogs = get_blogs_of_user( $user_id );
            if ( ! empty( $user_blogs ) ) {
                $primary_blog = reset( $user_blogs );
                wp_safe_redirect( $primary_blog->siteurl );
                exit;
            }
            wp_logout();
            wp_safe_redirect( home_url() );
            exit;
        }
    }
}
SA_Cross_Tenant_Security::init();
```

### 3.5 One-Time Login Token (Cross-Domain SSO)

```php
function sa_generate_login_url( int $blog_id, int $user_id ): string {
    $token = wp_generate_password( 32, false );
    set_transient( 'sa_otl_' . $token, array(
        'blog_id'  => $blog_id,
        'user_id'  => $user_id,
        'consumed' => false,
    ), 5 * MINUTE_IN_SECONDS );

    return add_query_arg( array(
        'sa_otl_token' => $token,
        'sa_otl_nonce' => wp_create_nonce( 'sa_otl_' . $token ),
    ), get_site_url( $blog_id ) . '/wp-login.php' );
}

// Consume token on login page
add_action( 'init', function () {
    if ( empty( $_GET['sa_otl_token'] ) ) return;
    $token = sanitize_key( $_GET['sa_otl_token'] );
    $data  = get_transient( 'sa_otl_' . $token );

    if ( ! $data || $data['consumed'] || ! wp_verify_nonce( $_GET['sa_otl_nonce'], 'sa_otl_' . $token ) ) {
        wp_die( 'Invalid or expired login token.' );
    }

    $data['consumed'] = true;
    set_transient( 'sa_otl_' . $token, $data, 5 * MINUTE_IN_SECONDS );

    wp_set_auth_cookie( $data['user_id'], true );
    delete_transient( 'sa_otl_' . $token );
    wp_safe_redirect( admin_url( 'admin.php?page=schedule-anything' ) );
    exit;
} );
```

---

## 4. Availability & Booking Engine

### 4.1 Availability Algorithm (6-Step Calendly Pattern)

Based on the [Calendly system design](https://www.techinterview.org/post/3233474486/):

```
Step 1: Generate candidate slots from availability rules
        ("Mon-Fri 9-5 ET, 30-min slots, 15-min buffer")

Step 2: Subtract busy times from connected calendars
        (Google FreeBusy API, Outlook — privacy-preserving, no event details)

Step 3: Subtract already-booked appointments
        (custom wp_mcp_appointments table, typed indexes)

Step 4: Apply timezone conversion
        (display in booker's timezone)

Step 5: Apply meeting limits
        ("max 5 per day")

Step 6: Return final available slots
        (cached with 2-min TTL, invalidated on calendar webhook)
```

**Target: < 500ms response time.**

### 4.2 Slot Hold for Double-Booking Prevention

```php
/**
 * Atomic slot hold using Redis SET NX. Pattern from Calendly.
 *
 * "NX flag ensures only one person holds the slot.
 * 5-minute TTL auto-releases abandoned holds."
 */
public function create_booking( array $data ): array|WP_Error {
    // Step 1: Re-validate availability (race condition check)
    $available = $this->get_available_slots( $data['service_id'], $data['staff_id'], ... );
    if ( ! $this->slot_is_available( $available, $data['start_time'] ) ) {
        return new WP_Error( 'slot_unavailable', 'This time is no longer available.' );
    }

    // Step 2: Atomic hold — SET NX with 5-min TTL
    $hold_key     = "sa_slot_hold:{$data['staff_id']}:{$data['start_time']}";
    $hold_session = wp_generate_uuid4();
    $redis        = $this->get_redis_client();
    $acquired     = $redis->set( $hold_key, $hold_session, array( 'nx', 'ex' => 300 ) );

    if ( ! $acquired ) {
        return new WP_Error( 'slot_held', 'This slot is being booked by another user.' );
    }

    try {
        // Step 3: Dual-write appointment (CPT + custom table)
        $appointment_id = $this->create_dual_write( $data );

        // Step 4: Async: create calendar event + send confirmation
        as_enqueue_async_action( 'sa_create_calendar_event', array( $appointment_id ), 'sa_bookings' );
        as_enqueue_async_action( 'sa_send_booking_confirmation', array( $appointment_id ), 'sa_bookings' );

        return array( 'appointment_id' => $appointment_id, 'status' => 'confirmed' );
    } finally {
        // Step 5: Release hold (only if we own it — Lua atomic check)
        $redis->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
            array( $hold_key, $hold_session ), 1
        );
    }
}
```

### 4.3 Custom Appointments Table (Typed, Indexed)

```sql
CREATE TABLE IF NOT EXISTS `{$wpdb->base_prefix}mcp_appointments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `blog_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CPT post ID for admin UI',
    `service_id` BIGINT UNSIGNED NOT NULL,
    `staff_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `client_name` VARCHAR(255) NOT NULL,
    `client_email` VARCHAR(255) NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `status` ENUM('pending','confirmed','cancelled','completed','no_show')
        NOT NULL DEFAULT 'pending',
    `google_event_id` VARCHAR(255) DEFAULT '',
    `outlook_event_id` VARCHAR(255) DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Optimized for "find conflicts for staff X on date Y"
    INDEX `idx_blog_staff_time` (`blog_id`, `staff_id`, `start_time`, `end_time`),
    INDEX `idx_blog_service_time` (`blog_id`, `service_id`, `start_time`),
    INDEX `idx_blog_date_status` (`blog_id`, `start_time`, `status`),

    -- Database-level double-booking prevention
    UNIQUE KEY `uq_booking` (`blog_id`, `staff_id`, `start_time`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 5. Schedule Execution Engine

### 5.1 Execution Flow

```
WordPress Cron (every minute)
    │ fires wp_mcp_ai_pro_schedule_exec
    ▼
Schedule Dispatcher (dispatch method)
    │ routes by schedule_type
    ├── TYPE_TASK: do_action(hook)
    ├── TYPE_WORKFLOW: execute tool steps sequentially
    ├── TYPE_ASSISTANT_RUN: AI chat with context
    ├── TYPE_CHANNEL_BROADCAST: multi-platform message
    └── TYPE_WORKFLOW_BUILDER: run saved DAG
    │
    ▼
Result Recorder
    ├── Run history (50-entry ring buffer)
    ├── Structured result envelope
    ├── Callback webhook (HMAC-signed)
    └── Failure notification (email + 6 chat platforms)
```

### 5.2 Migration: WP-Cron → Action Scheduler (at 500+ Tenants)

```php
/**
 * Phase 2: Replace WP-Cron with Action Scheduler for schedule dispatch.
 *
 * AS benefits:
 * - Proper concurrency control (prevents duplicate execution)
 * - WP-CLI runner for high throughput: wp action-scheduler run --batch-size=500
 * - Built-in retry with exponential backoff
 * - 100+ actions per batch vs. WP-Cron's one-at-a-time
 * - Separate queues per workload: 'sa_schedules', 'sa_email', 'sa_reports'
 */
class SA_Action_Scheduler_Dispatcher {

    public static function schedule( string $schedule_id, string $cron_interval, int $timestamp ) {
        WP_MCP_AI_Pro_Schedule_Manager::unschedule_wp_cron( $schedule_id );

        as_schedule_recurring_action(
            $timestamp,
            self::interval_to_seconds( $cron_interval ),
            'sa_dispatch_schedule',
            array( $schedule_id ),
            'sa_schedules',
            true  // unique — prevents duplicate scheduling
        );
    }
}

// Performance tuning for high volume
add_filter( 'action_scheduler_queue_runner_batch_size', function () {
    return defined( 'SA_HIGH_VOLUME' ) && SA_HIGH_VOLUME ? 100 : 25;
} );

add_filter( 'action_scheduler_queue_runner_time_limit', function () {
    return defined( 'SA_HIGH_VOLUME' ) && SA_HIGH_VOLUME ? 120 : 30;
} );
```

### 5.3 Batch Dispatch Optimization

```php
/**
 * When N schedules fire simultaneously, run them in a single
 * PHP process instead of spawning N separate processes.
 * Reduces process spawn overhead by ~80%.
 */
public static function dispatch_batch() {
    $schedules = self::load_schedules();
    $now       = time();
    $batch     = array();

    foreach ( $schedules as $id => $schedule ) {
        if ( $schedule['enabled'] && self::get_next_run_time( $id ) <= $now ) {
            $batch[] = $id;
        }
    }

    // Process up to 20 per batch (avoid timeout)
    foreach ( array_slice( $batch, 0, 20 ) as $id ) {
        self::dispatch( $id );
    }

    // If more remain, schedule next batch immediately
    if ( count( $batch ) > 20 ) {
        wp_schedule_single_event( time() + 5, self::DISPATCH_HOOK . '_batch' );
    }
}
```

---

## 6. React SPA Implementation

### 6.1 Auth Context (Nonce Management)

```typescript
// contexts/AuthContext.tsx — adapted from chat-spa pattern
export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [auth, setAuth] = useState<AuthState>({
    nonce: null, userId: null, isLoggedIn: false, isLoading: true,
  });

  useEffect(() => {
    apiFetch({ path: '/wp-json/nvoos-saas/v1/auth/nonce' })
      .then((data: any) => {
        setAuth({
          nonce: data.nonce,
          userId: data.user_id,
          isLoggedIn: data.logged_in,
          isLoading: false,
        });
        apiFetch.use(apiFetch.createNonceMiddleware(data.nonce));
      })
      .catch(() => setAuth({ nonce: null, userId: null, isLoggedIn: false, isLoading: false }));
  }, []);

  return <AuthContext.Provider value={auth}>{children}</AuthContext.Provider>;
}
```

### 6.2 React Flow Schedule Builder

```typescript
// components/builder/FlowCanvas.tsx
const nodeTypes = { toolNode: ToolNode, triggerNode: TriggerNode };

export function FlowCanvas() {
  const [nodes, setNodes, onNodesChange] = useNodesState([]);
  const [edges, setEdges, onEdgesChange] = useEdgesState([]);
  const [selectedNode, setSelectedNode] = useState<Node | null>(null);

  const onDrop = useCallback((event: React.DragEvent) => {
    event.preventDefault();
    const toolData = JSON.parse(event.dataTransfer.getData('application/reactflow'));
    const position = { x: event.clientX - 250, y: event.clientY - 50 };
    const newNode: Node = {
      id: `tool-${Date.now()}`,
      type: 'toolNode',
      position,
      data: { toolSlug: toolData.slug, toolName: toolData.name, toolkit: toolData.toolkit, arguments: {} },
    };
    setNodes((nds) => nds.concat(newNode));
  }, [setNodes]);

  const onSave = useCallback(() => {
    const scheduleData = serializeFlowToSchedule(nodes, edges);
    apiFetch({ path: '/wp-json/mcp-ai-pro/v1/schedules', method: 'POST', data: scheduleData });
  }, [nodes, edges]);

  return (
    <div className="flex h-full">
      <ToolPalette />
      <div className="flex-1 relative" onDrop={onDrop} onDragOver={(e) => e.preventDefault()}>
        <ReactFlow nodes={nodes} edges={edges} onNodesChange={onNodesChange}
          onEdgesChange={onEdgesChange} onConnect={onConnect}
          onNodeClick={(_, node) => setSelectedNode(node)} nodeTypes={nodeTypes} fitView>
          <Background /><Controls /><MiniMap />
        </ReactFlow>
      </div>
      <PropertyPanel node={selectedNode} onUpdate={(data) =>
        setNodes((nds) => nds.map((n) => (n.id === selectedNode?.id ? { ...n, data } : n)))
      } />
    </div>
  );
}
```

### 6.3 Booking Flow State Machine

```typescript
// hooks/useBooking.ts
type BookingStep =
  | 'selecting_service' | 'selecting_staff' | 'selecting_slot'
  | 'filling_form' | 'paying' | 'confirmed' | 'error';

export function useBooking(tenantSlug: string) {
  const [state, setState] = useState<BookingState>({
    step: 'selecting_service',
    serviceId: null, staffId: null, selectedSlot: null,
    bookerName: '', bookerEmail: '', bookerPhone: '',
    paymentIntentId: null, appointmentId: null, error: null,
  });

  const selectService = (id: number) => setState(s => ({ ...s, serviceId: id, step: 'selecting_staff' }));
  const selectStaff   = (id: number) => setState(s => ({ ...s, staffId: id, step: 'selecting_slot' }));
  const selectSlot    = (slot: any) => setState(s => ({ ...s, selectedSlot: slot, step: 'filling_form' }));

  const confirmBooking = async () => {
    try {
      const result = await apiFetch({
        path: '/wp-json/mcp-ai-pro/v1/bookings',
        method: 'POST',
        data: { service_id: state.serviceId, staff_id: state.staffId,
                start_time: state.selectedSlot!.start, end_time: state.selectedSlot!.end,
                client_name: state.bookerName, client_email: state.bookerEmail,
                client_phone: state.bookerPhone, tenant: tenantSlug },
      });
      setState(s => ({ ...s, appointmentId: result.appointment_id, step: 'confirmed' }));
    } catch (err: any) {
      setState(s => ({ ...s, step: 'error', error: err.message || 'Booking failed' }));
    }
  };

  return { state, selectService, selectStaff, selectSlot, confirmBooking };
}
```

---

## 7. Billing & Subscription System

### 7.1 Stripe Integration Flow

```
React SPA → Stripe Checkout (signup)
React SPA → Stripe Customer Portal (plan management)
Stripe → Cloud Worker /webhook (events)
Cloud Worker → D1 (tenant + usage records)
Cloud Worker → WP REST /tenants/provision (provisioning trigger)
```

### 7.2 Stripe Webhook Handler (Cloud Worker)

```typescript
// cloud-worker/src/subscriptions.ts
subscriptions.post('/webhook', async (c) => {
  const signature = c.req.header('stripe-signature');
  if (!signature) return c.json({ error: 'Missing signature' }, 400);

  let event: Stripe.Event;
  try {
    event = await stripe.webhooks.constructEventAsync(
      await c.req.text(), signature, env.STRIPE_WEBHOOK_SECRET
    );
  } catch (err) {
    return c.json({ error: 'Invalid signature' }, 401);
  }

  // Idempotency: check event_id
  const existing = await env.DB.prepare(
    'SELECT id FROM webhook_events WHERE event_id = ?'
  ).bind(event.id).first();
  if (existing) return c.json({ received: true, idempotent: true });

  await env.DB.prepare(
    'INSERT INTO webhook_events (event_id, event_type, created_at) VALUES (?, ?, ?)'
  ).bind(event.id, event.type, Math.floor(Date.now() / 1000)).run();

  switch (event.type) {
    case 'checkout.session.completed':
      return handleCheckoutCompleted(c, event.data.object);
    case 'invoice.paid':
      return handleInvoicePaid(c, event.data.object);
    case 'invoice.payment_failed':
      return handlePaymentFailed(c, event.data.object);
    case 'customer.subscription.deleted':
      return handleSubscriptionDeleted(c, event.data.object);
  }
  return c.json({ received: true });
});

async function handleCheckoutCompleted(c: Context, session: Stripe.Checkout.Session) {
  // Create tenant in D1 → trigger WP provisioning → update KV for router
  const tenantId = crypto.randomUUID();
  await c.env.DB.prepare(`INSERT INTO tenants (id, slug, tier, stripe_customer_id, ...) VALUES (...)`).run();

  const result = await fetch(`${c.env.WP_PLATFORM_URL}/wp-json/nvoos-saas/v1/tenants/provision`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-SaaS-API-Key': c.env.SAAS_API_KEY },
    body: JSON.stringify({ slug: session.metadata.tenant_slug, tier: session.metadata.tier, ... }),
  });

  await c.env.TENANT_KV.put(session.metadata.tenant_slug, result.site_url);
  return c.json({ ok: true });
}

async function handlePaymentFailed(c: Context, invoice: Stripe.Invoice) {
  // Suspend tenant
  await c.env.DB.prepare(
    "UPDATE tenants SET status = 'suspended' WHERE stripe_customer_id = ?"
  ).bind(invoice.customer as string).run();
  return c.json({ ok: true, action: 'tenant_suspended' });
}

async function handleSubscriptionDeleted(c: Context, subscription: Stripe.Subscription) {
  // Offboard tenant: export data → delete blog → remove from KV → mark cancelled
  const tenant = await c.env.DB.prepare(
    'SELECT * FROM tenants WHERE stripe_customer_id = ?'
  ).bind(subscription.customer as string).first();

  await fetch(`${c.env.WP_PLATFORM_URL}/wp-json/nvoos-saas/v1/tenants/${tenant.id}/offboard`, {
    method: 'POST', headers: { 'X-SaaS-API-Key': c.env.SAAS_API_KEY },
  });

  await c.env.TENANT_KV.delete(tenant.slug);
  await c.env.DB.prepare("UPDATE tenants SET status = 'cancelled' WHERE id = ?").bind(tenant.id).run();
  return c.json({ ok: true, action: 'tenant_offboarded' });
}
```

### 7.3 Stripe Products

| Product ID | Tier | Price/Month | Limits |
|---|---|---|---|
| `prod_starter` | starter | $49 | 5 toolkits, 3 users, 20 schedules |
| `prod_professional` | professional | $149 | 15 toolkits, 10 users, 100 schedules |
| `prod_enterprise` | enterprise | $499 | 30 toolkits, 50 users, 500 schedules |

---

## 8. Security Architecture

### 8.1 Threat Model

| Threat | Severity | Mitigation |
|---|---|---|
| Cross-tenant data access | Critical | MU plugin: `is_user_member_of_blog()` on every request. Multisite structural isolation. |
| Double-booking race condition | High | Redis SET NX atomic hold + DB UNIQUE constraint on `(blog_id, staff_id, start_time, status)`. |
| Stripe webhook replay | High | Idempotency via `event_id` PK in D1. HMAC-SHA256 signature verification (already built in SaaS Controller). |
| Tenant privilege escalation | Medium | Custom `sa_manage_workspace` capability (not `manage_options`). Per-endpoint capability checks. |
| Noisy neighbor | Medium | Per-tenant rate limiting at CF Worker. Hard limits per tier (schedules, tools, AI tokens). |
| AI cost abuse | Medium | Pre-paid wallet (built). Hard daily/monthly caps. BYOK default. Model restrictions per tier. |
| Session hijacking across subdomains | Medium | One-time login tokens (5-min TTL, single-use). WP cookies scoped per subdomain. |

### 8.2 REST Endpoint Security Matrix

| Endpoint | Auth | Capability | Rate Limit |
|---|---|---|---|
| `GET /bookings/slots` | None (public) | None | 30/min per IP |
| `POST /bookings` | None (public) | None | 10/min per IP, ReCAPTCHA v3 |
| `GET /schedules` | Nonce | `sa_manage_workspace` | 60/min |
| `POST /schedules` | Nonce | `sa_manage_workspace` | 30/min, validates tier limits |
| `POST /tenants/provision` | API Key (`X-SaaS-API-Key`) | None | IP-restricted (internal only) |
| `POST /tenants/offboard` | API Key (`X-SaaS-API-Key`) | None | IP-restricted (internal only) |

---

## 9. Database & Caching Strategy

### 9.1 Cache Layers

```
Cloudflare CDN (edge)
  ├── Static assets: JS/CSS/images (1-year cache)
  └── Public REST: GET /bookings/slots (30s TTL)
        │
    Redis (Cloudways managed)
      ├── Slot holds: SET NX with 5-min TTL
      ├── Calendar busy times: 2-min TTL
      ├── Session data: per-user
      └── REST response cache: GET /schedules (60s TTL)
        │
    WP 6.9+ Salted Query Cache
      ├── post-queries: CPT lists filtered by meta
      ├── term-queries: service categories
      └── user-queries: staff assignments
        │
    MySQL (Cloudways)
      ├── wp_posts / wp_postmeta: CPT data (per subsite)
      ├── wp_mcp_appointments: custom table (typed, indexed)
      ├── wp_options: schedule configs, settings
      └── Read replicas: for analytics queries
```

### 9.2 Query Cache Usage (WP 6.9+)

```php
function sa_get_cached_availability( int $service_id, int $staff_id, string $date ): array {
    $cache_key = "availability:{$service_id}:{$staff_id}:{$date}";
    $cached = wp_cache_get_salted( $cache_key, 'sa_availability' );
    if ( false !== $cached ) return $cached;

    $slots = WP_MCP_AI_Appointment_Repository::get_available_slots( $service_id, $staff_id, $date );
    wp_cache_set_salted( $cache_key, $slots, 'sa_availability', 120 );  // 2 min TTL
    return $slots;
}

// Invalidate on booking changes
add_action( 'sa_appointment_created', function () {
    wp_cache_set_last_changed( 'sa_availability' );
} );
```

---

## 10. Observability & Operations

### 10.1 Monitoring

| Signal | Tool | Tracks |
|---|---|---|
| Errors | Sentry | PHP exceptions, JS errors, CF Worker errors |
| Performance | Cloudflare Analytics | Request latency, cache hit rate, bandwidth |
| Uptime | UptimeRobot | scheduleanything.com, tenant subdomains, health endpoints |
| Billing | Stripe Dashboard | MRR, churn, invoices, failed payments |
| Usage | D1 tenant_usage | Schedules run, tools executed, AI tokens, storage |
| Audit | SaaS Controller audit log (built) | Provisioning, offboarding, plan changes |

### 10.2 Health Endpoints

```
GET /wp-json/nvoos-saas/v1/healthz
→ { status, version, multisite, blog_count, redis, db, last_provisioning }

GET /wp-json/mcp-ai-pro/v1/health
→ { status, schedule_manager, schedules_registered, next_cron_run }
```

### 10.3 Tenant Offboarding

```
1. Tenant cancels → Stripe webhook customer.subscription.deleted
2. Cloud Worker: status → 'cancelled', trigger WP offboarding
3. WordPress: export data XML+JSON → email 72h download link
4. After 72h: wpmu_delete_blog() → cascading delete
5. Cloud Worker: remove KV record, archive D1 tenant row
```

---

## 11. Week-by-Week Build Plan

### Phase 0: Foundation (Weeks 1-2)

| Wk | Tasks | Deliverables |
|---|---|---|
| 1 | Cloudways Multisite staging. Network-activate Base+Pro. Deploy Cloud Worker staging. Run full PHPUnit suite on 30 toolkits — file issues. | Staging ready. Test report. |
| 2 | Design DB schema. Finalize tenant router design. Set up CI/CD. Scaffold React SPA (Vite+TS+Tailwind). | Schema ERD. Worker spec. CI/CD running. SPA skeleton. |

### Phase 1: Core Infrastructure (Weeks 3-6)

| Wk | Tasks | Deliverables |
|---|---|---|
| 3 | Build tenant router: Hono, KV tenant→origin, rate limiting. Deploy to staging. | Router live. |
| 4 | Stripe billing: Products+Prices, D1 tenants table, subscription webhooks. Test with Stripe test clocks. | Billing webhooks processing. |
| 5 | Signup flow: React registration → Stripe Checkout → webhook → provisioning. Multisite provisioner. | E2E signup→workspace. |
| 6 | SPA auth layer (nonce + tenant context). Admin dashboard skeleton. Must-use security plugin. | SPA authenticating. Cross-tenant security active. |

### Phase 2: Core Scheduling (Weeks 7-10)

| Wk | Tasks | Deliverables |
|---|---|---|
| 7 | Schedule REST CRUD: `GET/POST/PUT/DELETE /mcp-ai-pro/v1/schedules`. Tenant-scoped. | Schedules API. |
| 8 | React Flow builder: ToolPalette, FlowCanvas, PropertyPanel, onSave→REST. | Visual builder functional. |
| 9 | Preset library: browse/search, one-click install. Run history page. | Presets + history. |
| 10 | Admin dashboard: toolkit toggles, AI config, user mgmt, execution stats. | Dashboard complete. |

### Phase 3: Tenant Experience (Weeks 11-14)

| Wk | Tasks | Deliverables |
|---|---|---|
| 11 | Public booking REST: slots + booking with Redis SET NX. Availability algorithm. | Booking API. |
| 12 | Booking portal components: ServicePicker→StaffPicker→SlotPicker→Form→Payment. useBooking hook. | Booking flow. |
| 13 | AI schedule builder: NL→workflow. Email templates (mjml). Custom appointments table + dual-write. | NL→workflow. Custom table active. |
| 14 | Tenant analytics: charts, billing summary. | Analytics dashboard. |

### Phase 4: Production Hardening (Weeks 15-16)

| Wk | Tasks | Deliverables |
|---|---|---|
| 15 | Redis + WP 6.9 query cache. Load testing (k6, 1K concurrent tenants). Fix bottlenecks. | Caching active. Load test report. |
| 16 | Security audit. Documentation. Production deploy: Cloudways, Cloud Worker, Stripe live, DNS. | **Live at scheduleanything.com.** |

---

## 12. Testing Strategy

### Test Pyramid

```
       ┌──────┐
       │ E2E  │  Playwright: signup→provision→create schedule→booking flow
       ├──────┤
       │ Int. │  PHPUnit: REST endpoints, cross-tenant isolation, provisioning
       ├──────┤
       │ Unit │  PHPUnit: Schedule Manager, Presets, Availability algorithm
       │      │  Vitest: React components, hooks, state machines
       └──────┘
```

### Critical Tests

| Layer | Test | Assertion |
|---|---|---|
| Unit | `get_available_slots()` with overlapping bookings | Returns empty when fully booked |
| Unit | `create_booking()` with concurrent race | Second call returns WP_Error |
| Unit | `SA_Multisite_Provisioner::provision()` | Creates subsite, activates plugins, seeds presets |
| Unit | `SA_Cross_Tenant_Security::validate_session()` | Redirects on wrong blog |
| Integration | Tenant A GET /schedules → only Tenant A data | No cross-tenant data leak |
| Integration | POST /bookings without auth → 200 | Public endpoint works |
| Integration | POST /tenants/provision without API key → 401 | Internal endpoint secured |
| E2E | Signup → provision → login → create schedule → trigger | Full tenant lifecycle |
| E2E | Public booking: service→staff→slot→confirm | Booking flow E2E |

---

## Appendices

### Appendix A: Environment Variables & Secrets

| Variable | Location | Purpose |
|---|---|---|
| `CLOUDFLARE_API_TOKEN` | CI/CD + SaaS Controller | Deploy Workers, manage KV/D1 |
| `STRIPE_SECRET_KEY` | Cloud Worker (secret) | Stripe API |
| `STRIPE_WEBHOOK_SECRET` | Cloud Worker (secret) | Webhook signature verification |
| `SAAS_API_KEY` | Cloud Worker + WP (shared) | Internal service-to-service auth |
| `CLOUDWAYS_EMAIL` / `CLOUDWAYS_API_KEY` | WP Platform Plugin | Enterprise provisioning |
| `REDIS_URL` | WP (wp-config.php) | Slot holds + cache |

### Appendix B: WordPress Constants

```php
// Multisite
define( 'WP_ALLOW_MULTISITE', true );
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', true );

// Performance
define( 'WP_MEMORY_LIMIT', '512M' );
define( 'SA_HIGH_VOLUME', false );     // Set true at 500+ tenants

// Redis
define( 'WP_REDIS_HOST', getenv( 'REDIS_HOST' ) ?: '127.0.0.1' );
```

### Appendix C: Sources

| Source | URL |
|---|---|
| SampleHQ WP Multisite SaaS | https://bojanjosifoski.com/multi-tenant-saas-architecture-wordpress/ |
| Calendly System Design | https://www.techinterview.org/post/3233474486/ |
| Cloudflare for SaaS Ref Arch | https://developers.cloudflare.com/reference-architecture/design-guides/leveraging-cloudflare-for-your-saas-applications/ |
| Action Scheduler Perf Docs | https://actionscheduler.org/perf/ |
| Stripe Subscriptions Build Guide | https://docs.stripe.com/billing/subscriptions/build-subscriptions |
| Multi-Tenant SaaS Architecture Guide | https://codeboxr.com/multi-tenant-saas-architecture-complete-guide-models-design-patterns-and-scaling-strategy/ |
| NV oOS Schedule Manager | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php` |
| NV oOS Cloud Worker | `addons/cloud-worker/` |

---

*This implementation plan is a living document. Update as code is written, tests reveal gaps, and production data informs scaling decisions.*
