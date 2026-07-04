# JetBooking & JetAppointment — Calendar & Places Toolkit Integration Proposal

**Date:** June 30, 2026  
**Status:** 📋 PROPOSAL  
**Target Release:** NV oOS Pro v1.5.0  
**Reference:** Shopify Sync Pro Toolkit (v1.3.0), FlowHub Pro Toolkit (v1.2.0) — adapter pattern  
**Estimated Duration:** 6 weeks (28 stories, ~40-54 engineering hours)

---

## Executive Summary

The Calendar Booking Toolkit and Places Management Toolkit are two established NV oOS Pro features that currently operate in complete isolation from Crocoblock's JetBooking (daily booking) and JetAppointment (hourly appointment) plugins — despite both serving overlapping use cases on the same WordPress sites. This proposal introduces a layered adapter architecture that makes both toolkits Jet-aware without breaking existing behavior, adds bidirectional data sync, and links Places geospatial data into booking/appointment workflows. The result: AI assistants can manage bookings across *all* systems from a single tool surface, and location-aware scheduling becomes possible for the first time.

---

## 1. Problem Statement

### 1.1 Current State

| Toolkit | Tools | Data Model | Jet Awareness |
|---|---|---|---|
| Calendar Booking | 20 tools | `mcp_appointment`, `mcp_service`, `mcp_staff` CPTs | **None** |
| Places Management | 9 tools | `mcp_ai_place` CPT | **None** |

A site running both NV oOS and JetBooking/JetAppointment has **two parallel booking systems** with zero interoperability. AI assistants can only see and manage NV oOS's own CPTs — they are blind to bookings that users created through JetBooking forms or JetAppointment workflows on the frontend.

### 1.2 User Impact

- **Fragmented visibility:** An admin asking "show me all bookings for next Tuesday" gets only NV oOS appointments — JetBooking and JetAppointment bookings are invisible.
- **Schedule conflicts:** NV oOS availability checks don't consider JetAppointment slots or JetBooking unit reservations, leading to double-bookings.
- **Duplicate data entry:** Services and providers configured in JetAppointment must be manually recreated as `mcp_service` / `mcp_staff` entries if AI tools are to reference them.
- **Missed location opportunities:** The Places toolkit knows where a business is located but can't answer "are there any available appointments at this place?" — a query that only makes sense when places are linked to booking systems.

### 1.3 Root Cause

The Calendar Booking Toolkit was designed as a self-contained system with its own CPTs. No abstraction layer exists for external booking providers. Every tool (`check_availability`, `create_appointment`, `get_calendar_view`) hardcodes queries against `mcp_appointment` / `mcp_service` / `mcp_staff` post types.

---

## 2. Proposed Solution

### 2.1 Architecture: Adapter Layer

Introduce a thin adapter layer between the tool layer and external booking systems:

```
┌──────────────────────────────────────────────────────────────┐
│                    TOOL LAYER (unchanged API)                 │
│  check_availability  create_appointment  get_calendar_view   │
│  get_available_slots  optimize_schedule  list_places  ...    │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│                 BOOKING ADAPTER FACTORY                       │
│  ┌─────────────────┐  ┌─────────────────┐                    │
│  │ JetAppointment   │  │ JetBooking      │  → Future:        │
│  │ Adapter          │  │ Adapter         │    SimplyBook.me,  │
│  │ (REST API)       │  │ (REST + DB)     │    Amelia, etc.    │
│  └─────────────────┘  └─────────────────┘                    │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│                   EXTERNAL SYSTEMS                            │
│  JetAppointment DB    JetBooking DB    mcp_appointment CPT   │
│  (wp_jet_appointment) (wp_jet_apartment_*)  (native)         │
└──────────────────────────────────────────────────────────────┘
```

**Key principle:** Tools never call JetBooking or JetAppointment APIs directly. They ask the factory "do you have an adapter for X?" and delegate if one exists. When no adapter is available, tools fall back to their existing native behavior — unchanged from today.

### 2.2 Feature Detection (Not Plugin Detection)

The factory checks for *capability*, not just plugin activation:

```php
// JetAppointment detection
function_exists('jet_engine')
  && $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}jet_appointment'")
  && get_option('jet_appointment_settings') !== false

// JetBooking detection  
class_exists('Jet_Booking')
  && $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}jet_apartment_bookings'")
```

This handles edge cases: renamed plugin directories, mu-plugin installations, partially-configured setups.

### 2.3 Canonical Envelope Consistency

Every adapter method returns the same `array('success' => true, ...)` / `WP_Error` format used by all NV oOS tools. The outer tool layer never sees JetAppointment's raw REST responses or JetBooking's database row format.

---

## 3. Scope

### 3.1 In Scope (This Proposal)

| Phase | Description | Stories |
|---|---|---|
| **Phase 1:** JetAppointment Adapter | Detection, REST API client, provider/service/slot/appointment CRUD | 6 |
| **Phase 2:** JetBooking Adapter | Detection, REST + DB queries, unit availability, booking CRUD | 6 |
| **Phase 3:** Enhanced Calendar Tools | Make 7 existing tools Jet-aware + 7 new sync/query tools | 8 |
| **Phase 4:** Places ↔ Booking Bridge | Link places to JA providers/JB instances + geo-aware booking queries | 4 |
| **Phase 5:** Settings & Admin | Feature toggles, API auth config, sync controls, health dashboard | 4 |
| **Total** | | **28 stories** |

### 3.2 Out of Scope

- **JetFormBuilder form creation** — proposal covers AI-tool access to booking data, not form building.
- **WooCommerce payment flow for JetBooking** — the adapter reads/writes bookings; payment processing remains JetBooking's responsibility.
- **Real-time WebSocket sync** — polling-based sync with configurable intervals, not push.
- **Other booking plugins** (Amelia, Bookly, Simply Schedule Appointments) — adapter pattern makes these feasible as follow-up work but they are not included here.

---

## 4. Industry Research & Best Practices

### 4.1 Adapter Pattern Precedent

The adapter pattern is the standard approach for multi-provider integrations:

- **Shopify SDKs** — Shopify's own ecosystem uses adapter layers (Storefront API adapter, Admin API adapter) so themes/apps work across REST and GraphQL backends.
- **WooCommerce Payment Gateways** — Every payment gateway (Stripe, PayPal, Square) implements the `WC_Payment_Gateway` adapter interface. WooCommerce core never calls Stripe's API directly.
- **WordPress Object Cache** — `WP_Object_Cache` is an adapter: Redis, Memcached, and APCu all implement the same interface. Plugin code calls `wp_cache_get()`, never `$redis->get()`.

**Application to this proposal:** `JetAppointment_Adapter` and `JetBooking_Adapter` are the booking-system equivalents of a payment gateway class. Each implements a consistent internal interface, and the tool layer is the "WooCommerce core" that only talks to the interface.

### 4.2 Multi-Source Schedule Unification

Industry standards for merging schedules from multiple sources:

- **Google Calendar API** — Free/busy endpoint merges availability across multiple calendars. Our `get_calendar_view` tool should follow the same pattern: query all sources, merge, resolve conflicts.
- **CalDAV (RFC 4791)** — Standard for calendar data exchange. Appointment records from JetAppointment can be represented as VEVENT objects for export/ICS generation already supported by the `export_calendar_ics` tool.
- **iCalendar (RFC 5545)** — The existing `export_calendar_ics` tool already produces ICS files. JetBooking and JetAppointment data should be included in exports.

### 4.3 Geospatial + Booking Patterns

Industry patterns for location-aware booking:

- **Airbnb** — Search returns listings (places) with embedded availability calendars. The Places toolkit's `list_places` should be able to annotate each place with its booking availability status.
- **OpenTable** — Restaurant search returns venues with real-time table availability. Each place carries a "bookable" flag and available time slots.
- **Google Maps Places API** — Returns `opening_hours` and `business_status`. NV oOS places already model business hours; adding booking availability is a natural extension.

**Application:** The `find_bookable_places` tool should combine the existing Haversine-based radius search from `list_places` with adapter-provided availability data — answering "show me restaurants within 5km that have a table for 4 at 7pm."

### 4.4 REST API Authentication Standards

JetAppointment's REST API uses WordPress Application Passwords (introduced in WP 5.6). This is the recommended approach over:

- ❌ Basic Auth with username/password (deprecated in WP core)
- ❌ Hardcoded API keys in wp-config.php (security risk, already flagged by the `wp-security-secrets` skill)
- ✅ **Application Passwords** — scoped to a user, revocable, generated via WP admin

**Application:** The adapter should read credentials from NV oOS's existing Password Vault (secrets manager), not from new option keys. This matches the pattern used by the Google Calendar and Outlook Calendar sync tools.

### 4.5 Conflict Resolution Strategy

When the same time slot appears in multiple systems:

| Scenario | Resolution |
|---|---|
| NV oOS appointment + JetAppointment appointment at same time | Report as conflict, flag both system sources |
| JetBooking unit booked + JetAppointment slot requested | Report unit unavailable, suggest alternative time |
| NV oOS blocked time + JetAppointment slot | Honor block (NV oOS takes precedence as system of record) |

The `check_availability` tool should return per-system availability status, not just a boolean — so AI assistants can explain *why* a slot is unavailable and suggest alternatives.

---

## 5. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| JetAppointment REST API changes between versions | Medium | Medium | Version detection + graceful fallback to "adapter unavailable" |
| JetBooking DB schema differs between "Plain" and "WooCommerce" modes | High | Medium | Adapter detects mode and branches internally; external API unchanged |
| Direct `$wpdb` queries on JetBooking tables cause performance issues on large sites | Low | Medium | Limit results, use indexed columns, cache in transients |
| Application Password auth fails due to server config (Basic Auth disabled) | Low | High | Detect auth method availability; fall back to cookie-based nonce for same-origin requests |
| Bidirectional sync creates duplicate records | Medium | High | Use `_jetappointment_id` / `_jetbooking_id` meta fields as foreign keys; idempotent upsert logic |
| Places CPT may have stale coordinates after address change | Low | Low | Re-geocode on address update (already handled by `WP_MCP_AI_Place_Helper::maybe_geocode()`) |

---

## 6. Success Criteria

1. **Zero-breaking-change guarantee:** All existing calendar booking and places tools pass their current PHPUnit tests with no modifications to test assertions.
2. **JetAppointment visibility:** `get_calendar_view` includes JetAppointment appointments when the adapter is active.
3. **Unified availability:** `check_availability` detects conflicts across NV oOS + JetAppointment + JetBooking when all three are active.
4. **Place-to-booking linking:** `list_places` can filter by `has_bookings_available`, returning only places with bookable slots.
5. **Adapter health:** Admin dashboard shows green/yellow/red status for each adapter with actionable error messages.
6. **Test coverage:** ≥80% coverage on adapter classes; ≥70% on enhanced tool methods.

---

## 7. Dependencies

- **JetAppointment v3.0+** (for REST API endpoints used by the adapter)
- **JetBooking v3.5+** (for REST API and table structure)
- **JetEngine** (required by both Jet plugins; already a soft dependency of NV oOS)
- **WordPress 6.0+** (Application Passwords support)
- **NV oOS Password Vault** (for secure credential storage — already exists)

---

## 8. Related Documents

- [`JETBOOKING-JETAPPOINTMENT-IMPLEMENTATION-PLAN.md`](../plans/JETBOOKING-JETAPPOINTMENT-IMPLEMENTATION-PLAN.md) — Detailed implementation plan with story breakdown
- [`CLAUDE.md`](../../../CLAUDE.md) — PHP compat, tool sanitisation, canonical envelope rules
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — Nonce, capability, and escaping rules for new tools
- [`.context/tool-registry.md`](../../../.context/tool-registry.md) — Tool registration lifecycle
- [`addons/pro/includes/tools/calendar-booking/README.md`](../../../addons/pro/includes/tools/calendar-booking/README.md) — Current calendar booking tool inventory
- [`addons/pro/includes/tools/places/README.md`](../../../addons/pro/includes/tools/places/README.md) — Current places tool inventory
- [JetAppointment REST API Gist](https://gist.github.com/Crocoblock/b0797f1011bdae579e2a4893e12d6ce2) — External reference
- [JetBooking Knowledge Base](https://crocoblock.com/knowledge-base/jetbooking/) — External reference

---

## 9. Approval

| Role | Name | Date | Status |
|---|---|---|---|
| Author | AI Agent (Zed) | 2026-06-30 | — |
| Reviewer | — | — | ⏳ Pending |
| Approver | — | — | ⏳ Pending |
