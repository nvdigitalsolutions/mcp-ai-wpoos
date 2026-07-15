# Pro Toolkit MCP Servers — Expansion Plan (Phase 8+)

> **Status:** 📋 PROPOSAL  
> **Date:** 2026-07-16  
> **Scope:** Promote 4 additional Pro toolkits to first-class MCP servers + industry best-practice hardening  
> **Target:** NV oOS Pro v1.5.0+  
> **Related:** [ADR_002_toolkit_mcp_servers.md](../architecture-decisions/ADR_002_toolkit_mcp_servers.md), [FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md](./FLOWHUB-INVENTORY-SYNC-INTEGRATION-PROPOSAL.md), [SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md](./SHOPIFY-SYNC-PRO-TOOLKIT-PROPOSAL.md)

---

## Table of Contents

1. [Research Summary — Industry Best Practices](#1-research-summary--industry-best-practices)
2. [Gap Analysis — Current State](#2-gap-analysis--current-state)
3. [Proposed Architecture — Four New Servers](#3-proposed-architecture--four-new-servers)
4. [Shared Infrastructure — `ScheduledToolkitServerTrait`](#4-shared-infrastructure--scheduledtoolkitservertrait)
5. [Per-Server Specifications](#5-per-server-specifications)
   - [5.1 Pro Scheduler MCP Server](#51-pro-scheduler-mcp-server)
   - [5.2 FlowHub Inventory Sync MCP Server](#52-flowhub-inventory-sync-mcp-server)
   - [5.3 Shopify Sync MCP Server](#53-shopify-sync-mcp-server)
   - [5.4 EZuite ERP Sync MCP Server](#54-ezuite-erp-sync-mcp-server)
6. [Hardening Annotations — Existing 29-Server Fleet](#6-hardening-annotations--existing-29-server-fleet)
7. [Implementation Phases](#7-implementation-phases)
8. [Test Coverage Plan](#8-test-coverage-plan)
9. [Effort Estimation](#9-effort-estimation)
10. [Success Metrics](#10-success-metrics)
11. [Risk Register](#11-risk-register)
12. [Decision Required](#12-decision-required)
13. [Future Phase 9 — Pro Workflow Builder](#13-future-phase-9--pro-workflow-builder)
- [Appendices](#appendices)

---

## Executive Summary

The per-toolkit MCP server framework (ADR 002) currently covers **29 servers** across Phases 1, 2, and 6. However, four production-grade Pro toolkits — the **Pro Scheduler**, **FlowHub Inventory Sync**, **Shopify Sync**, and **EZuite ERP Sync** — remain excluded despite each owning a mature tool surface, admin page, and Action Scheduler-backed sync engine.

This proposal promotes all four to **Tier-1 toolkit MCP servers** and layers on industry best-practice hardening drawn from the 2025–2026 MCP specification guidance: per-server rate limiting, OAuth 2.0-aligned credential scoping, audit-trail completeness, and focused-domain server decomposition.

**Key Recommendation:** Proceed with a Phase 8 that adds four new MCP servers, a shared **`ScheduledToolkitServerTrait`** for the sync engine pattern, and hardening annotations across the existing 29-server fleet.

**Value Proposition:** External MCP client hosts (Claude Desktop, Cursor, Continue.dev, etc.) gain structured, rate-limited, auditable JSON-RPC access to inventory sync and scheduled task orchestration — without depending on the monolithic `/mcp-ai/v1/mcp` endpoint. Site operators gain per-toolkit governance that matches the existing CRM/Ecommerce/Cloudways experience.

---

## 1. Research Summary — Industry Best Practices

### 1.1 MCP Server Design Principles (2025–2026)

Research across the MCP specification ecosystem (Model Context Protocol Best Practices guide, Anthropic's MCP documentation, Digital Applied's enterprise MCP patterns) converges on five principles:

| Principle | What It Means | Relevance to NV oOS |
|---|---|---|
| **Focused-domain servers** | One MCP server per bounded context; avoid mega-servers that expose every tool to every client | ADR 002 already aligns — each toolkit is its own server. This proposal extends that pattern to 4 more toolkits. |
| **Least-privilege by default** | Tools default to read-only; write/edit tools behind explicit roles and per-tool, per-parameter authorization | Sync toolkits read from CCT cache (read-only); sync trigger is a privileged write operation. MCP server descriptor advertises the distinction. |
| **Strong input validation** | Reject invalid inputs at the first failure point; use JSON Schema for parameter contracts | All NV oOS tools already declare JSON Schema `$input_schema`. MCP server `tools/list` surfaces those schemas natively. |
| **Observability by design** | Every cross-server call is audited; rate-limit and payload-limit enforcement per server | Phase 4 audit trail already exists; Phase 3c per-server limits already exist. This proposal adds annotations for limits that flow into the `/mcp` descriptor. |
| **Authentication scoping** | OAuth 2.0-aligned token scoping per tool and per action; toolkit-scoped credentials (not user-scoped) | Phase 3d toolkit-scoped credentials already exist. This proposal adds `read_only` scope flags per tool in the descriptor. |

### 1.2 Sync-Server Patterns in Enterprise MCP Deployments

Analysis of enterprise MCP deployments (Shopify POS connectors, ERP middleware, WordPress automation servers) reveals a consistent "cache-first sync server" pattern:

```
External API ──(Action Scheduler poll)──▶ Local CCT Cache
                                                │
                        ┌───────────────────────┼───────────────────────┐
                        ▼                       ▼                       ▼
                  Read Tools             Write Tools             Admin Tools
                  (CCT queries,          (sync trigger,          (settings,
                   zero API cost,         WC writeback,           connection
                   < 50ms latency)        idempotent)             config)
```

This pattern is already implemented in FlowHub, Shopify Sync, and EZuite. Promoting them to MCP servers simply wraps the existing tool surface in a JSON-RPC endpoint with discovery, governance, and audit — zero code changes to the tools themselves.

### 1.3 Scheduler-as-MCP-Server Patterns

The 2025–2026 MCP ecosystem includes several scheduler MCP servers (PhialsBasement/scheduler-mcp, Zapier Cronfree Time Scheduler, n8n MCP nodes). Common patterns:

- **Cron-expression scheduling** — `tools/call` for create/update/delete/list
- **Execution history** — `resources/list` for past runs per schedule
- **Dry-run capability** — validate a schedule before committing
- **Result delivery** — `prompts/get` for retrieving formatted run results
- **Channel broadcast** — scheduled messages to Slack/Teams/Discord via unified tools

The Pro Scheduler already implements all five patterns. Promoting it to an MCP server exposes them to external MCP clients.

---

## 2. Gap Analysis — Current State

### 2.1 The Four Candidate Toolkits

| Toolkit | Tools | Sync Engine | Admin Page | MCP Server? |
|---|---|---|---|---|
| **Pro Scheduler** | 14 orchestration tools (`create_pro_schedule`, `list_pro_schedules`, `update_pro_schedule`, `delete_pro_schedule`, `get_schedule_run_history`, `get_schedule_latest_result`, `dry_run_pro_schedule`, `render_schedule_result`, `schedule_channel_broadcast`, `plan_schedules_from_workflow`, `configure_schedule_widget_defaults`, `get_session_status`, `manage_autonomous_session`, `create_execution_prompt`) | `WP_MCP_AI_Pro_Schedule_Manager` | ✅ `nvoos-pro-schedule-toolkit` (5 tabs: Overview, Configuration, Tools, Research, Help, MCP Server) | ❌ Admin page has "MCP Server" tab wired — but no server class exists |
| **FlowHub Inventory Sync** | 6 tools (`flowhub_inventory`, `flowhub_products`, `flowhub_locations`, `flowhub_sync`, `flowhub_settings`, `flowhub_analytics`) | `WP_MCP_AI_FlowHub_Sync_Engine` | ✅ FlowHub Toolkit (top-level menu, position 57) | ❌ |
| **Shopify Sync** | 5 tools (`shopify_sync_inventory`, `shopify_sync_products`, `shopify_sync_orders`, `shopify_sync_settings`, `shopify_sync_analytics`) | `WP_MCP_AI_Shopify_Sync_Engine` | ✅ Shopify Sync (top-level menu, position 58) | ❌ |
| **EZuite ERP Sync** | 6 tools (`ezuite_inventory`, `ezuite_erp_get_products`, `ezuite_erp`, `ezuite_settings`, `ezuite_sync`, plus alert manager) | `WP_MCP_AI_EZuite_Sync_Engine` | ✅ EZuite Toolkit (top-level menu) | ❌ |

### 2.2 Comparison with Existing Tier-1 Servers

| Tier-1 Server | Native Surfaces | Tools | Candidate Tools |
|---|---|---|---|
| Ecommerce (existing) | research-product, product-consolidate | 22 tools exposed | 22 |
| CRM (existing) | research-company, research-post, research-page, research-place | 70+ tools exposed | 70+ |
| **Pro Scheduler (proposed)** | research-schedule | 14 tools | 14 |
| **FlowHub (proposed)** | — (tools-only) | 6 tools | 6 |
| **Shopify Sync (proposed)** | — (tools-only) | 5 tools | 5 |
| **EZuite (proposed)** | — (tools-only) | 6 tools | 6 |

All four candidates are **tools-only servers** (no ingestion surfaces) — analogous to DietPi, AI Tool Builder, Social Media, and Multilingual in the existing fleet.

### 2.3 MCP Server Page Wiring Status

The Pro Scheduler admin page (`class-wp-mcp-ai-pro-schedule-toolkit-settings-page.php`) already loads and references an "MCP Server" tab (visible at line 227 of `mcp-ai-wpoos-pro.php`). The tab wiring exists but the MCP server class does not — making this a **low-effort completion** rather than a greenfield build.

FlowHub, Shopify Sync, and EZuite admin pages extend `WP_MCP_AI_Toolkit_Settings_Base`, which automatically grows an "MCP Server" tab when a server is registered for that toolkit. No admin page changes are required — the tab appears automatically once the server class is registered.

---

## 3. Proposed Architecture — Four New Servers

### 3.1 File Placement

Following the convention established by the 29 existing servers:

```
addons/pro/includes/mcp-servers/servers/
├── class-wp-mcp-ai-pro-scheduler-mcp-server.php      # NEW
├── class-wp-mcp-ai-flowhub-mcp-server.php            # NEW
├── class-wp-mcp-ai-shopify-sync-mcp-server.php       # NEW
├── class-wp-mcp-ai-ezuite-mcp-server.php             # NEW
└── ... (29 existing servers)
```

Plus one shared trait:

```
addons/pro/includes/mcp-servers/
├── trait-wp-mcp-ai-scheduled-toolkit-server.php      # NEW
└── ... (existing framework files)
```

### 3.2 Registration in `mcp-servers-init.php`

```php
// Phase 8 — Pro Scheduler + Inventory Sync MCP Servers (4 servers).
require_once __DIR__ . '/servers/class-wp-mcp-ai-pro-scheduler-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-flowhub-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-shopify-sync-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-ezuite-mcp-server.php';
```

And in the `wp_mcp_ai_register_toolkit_servers` callback:

```php
// Phase 8 — Pro Scheduler + Inventory Sync servers.
$registry->register( new WP_MCP_AI_Pro_Scheduler_MCP_Server() );
$registry->register( new WP_MCP_AI_FlowHub_MCP_Server() );
$registry->register( new WP_MCP_AI_Shopify_Sync_MCP_Server() );
$registry->register( new WP_MCP_AI_EZuite_MCP_Server() );
```

### 3.3 Server Count: 29 → 33

Post-implementation the fleet grows from 29 to 33 toolkit MCP servers.

---

## 4. Shared Infrastructure — `ScheduledToolkitServerTrait`

FlowHub, Shopify Sync, and EZuite share an identical architectural pattern:

1. Read tools query a CCT cache (zero API cost)
2. A sync trigger tool invokes an Action Scheduler hook
3. Connection validation is powered by a shared `ConnectionResolver` trait
4. Rate limits apply to the sync trigger, not to CCT reads
5. The server descriptor needs `limits.sync_interval_seconds` annotation

Rather than duplicate this logic across three server classes, we extract a shared `WP_MCP_AI_Scheduled_Toolkit_Server_Trait`:

```php
/**
 * Trait for toolkit MCP servers backed by an Action Scheduler sync engine.
 *
 * Provides:
 * - get_sync_interval()           — reads the configured sync interval from the
 *                                   sync engine's option key.
 * - get_sync_status()             — last sync timestamp, status, row count.
 * - get_connection_status()       — whether the remote API connection is healthy.
 * - annotate_sync_limits()        — merges sync_interval_seconds into the
 *                                   effective_limits() return.
 * - sync_tool_is_read_only()      — marks the sync trigger as write, all others
 *                                   as read (for descriptor scoping).
 */
trait WP_MCP_AI_Scheduled_Toolkit_Server_Trait {

    abstract public function get_sync_engine_class();
    abstract public function get_sync_hook_name();

    public function get_sync_interval() { ... }
    public function get_sync_status() { ... }
    public function get_connection_status() { ... }
    public function annotate_sync_limits( array $limits ) { ... }
    public function sync_tool_is_read_only( string $tool_slug ) { ... }
}
```

This trait is consumed by FlowHub, Shopify Sync, and EZuite servers. The Pro Scheduler server does NOT use this trait — its architecture is based on WP-Cron + `WP_MCP_AI_Pro_Schedule_Manager`, not Action Scheduler + CCT cache + external API.

---

## 5. Per-Server Specifications

### 5.1 Pro Scheduler MCP Server

| Field | Value |
|---|---|
| **Slug** | `pro-scheduler` |
| **Class** | `WP_MCP_AI_Pro_Scheduler_MCP_Server` |
| **Extends** | `WP_MCP_AI_Toolkit_Server_Base` |
| **Ingestion surfaces** | `research-schedule` (existing R&A page at `nvoos-pro-schedule-research`) |
| **Mounted surfaces** | None |
| **Candidate tools** | 14 tools (see [§2.1](#21-the-four-candidate-toolkits)) |
| **Rate limit** | 30 req/min (default; configurable) — scheduling is low-frequency |
| **Max payload** | 64 KB (schedule definitions are compact JSON) |
| **Max iterations** | 5 (schedule CRUD is simple; assistant_run dispatch may chain) |

#### Tools exposed via `tools/list`

```
pro-scheduler.create_pro_schedule          (write)
pro-scheduler.list_pro_schedules           (read)
pro-scheduler.update_pro_schedule          (write)
pro-scheduler.delete_pro_schedule          (write)
pro-scheduler.get_schedule_run_history     (read)
pro-scheduler.get_schedule_latest_result   (read)
pro-scheduler.dry_run_pro_schedule         (read)
pro-scheduler.render_schedule_result       (read)
pro-scheduler.schedule_channel_broadcast   (write)
pro-scheduler.plan_schedules_from_workflow (write)
pro-scheduler.configure_schedule_widget_defaults (write)
pro-scheduler.get_session_status           (read)
pro-scheduler.manage_autonomous_session    (write)
pro-scheduler.create_execution_prompt      (read)
```

#### Resources exposed via `resources/list`

```
nvoos://pro-scheduler/schedules            — list of all managed schedules
nvoos://pro-scheduler/schedules/{id}       — single schedule detail
nvoos://pro-scheduler/history/{schedule_id} — execution history ring buffer
nvoos://pro-scheduler/results/{schedule_id} — latest structured result envelope
nvoos://pro-scheduler/presets              — installable schedule presets catalog
```

#### Prompts exposed via `prompts/list`

```
pro-scheduler.research_add.schedule        — R&A Schedule prompt template
```

#### Descriptor annotations

```json
{
  "slug": "pro-scheduler",
  "name": "Pro Scheduler",
  "version": "1.0.0",
  "description": "Managed scheduled tasks, workflows, assistant runs, and channel broadcasts with retry, failure notification, run history, and dry-run validation.",
  "capabilities": {
    "tools": { "listChanged": true },
    "resources": { "listChanged": true, "subscribe": false },
    "prompts": { "listChanged": true }
  },
  "limits": {
    "requests_per_minute": 30,
    "max_payload_bytes": 65536,
    "max_iterations": 5
  },
  "surfaces": {
    "native": [
      {
        "type": "research_add",
        "page_slug": "nvoos-pro-schedule-research",
        "entity_type": "mcp_ai_schedule",
        "label": "Research & Add Schedule"
      }
    ],
    "mounted": []
  }
}
```

---

### 5.2 FlowHub Inventory Sync MCP Server

| Field | Value |
|---|---|
| **Slug** | `flowhub` |
| **Class** | `WP_MCP_AI_FlowHub_MCP_Server` |
| **Extends** | `WP_MCP_AI_Toolkit_Server_Base` |
| **Uses** | `WP_MCP_AI_Scheduled_Toolkit_Server_Trait` |
| **Ingestion surfaces** | None (tools-only server) |
| **Mounted surfaces** | None |
| **Candidate tools** | 6 tools |
| **Rate limit** | 60 req/min (CCT reads are cheap; sync trigger is throttled) |
| **Max payload** | 256 KB (inventory lists can be large) |
| **Max iterations** | 3 |

#### Tools exposed via `tools/list`

```
flowhub.inventory              (read)   — CCT cache query, zero API cost
flowhub.products               (read)   — CCT cache query
flowhub.locations              (read)   — CCT cache query
flowhub.sync                   (write)  — triggers Action Scheduler sync
flowhub.settings               (read)   — connection status, sync interval
flowhub.analytics              (read)   — CCT aggregate queries
```

#### Resources exposed via `resources/list`

```
nvoos://flowhub/inventory                  — paginated inventory (CCT-backed)
nvoos://flowhub/products                   — product catalog (CCT-backed)
nvoos://flowhub/locations                  — dispensary locations with stock counts
nvoos://flowhub/sync-status                — last sync timestamp, status, row count
nvoos://flowhub/connection-status          — FlowHub API health check
```

#### Descriptor annotations

```json
{
  "slug": "flowhub",
  "name": "FlowHub Inventory Sync",
  "version": "1.0.0",
  "description": "Cannabis dispensary inventory sync — FlowHub POS → WooCommerce via JetEngine CCT cache. AI assistants query inventory, products, and locations instantly from local data.",
  "capabilities": {
    "tools": { "listChanged": true },
    "resources": { "listChanged": true, "subscribe": false },
    "prompts": { "listChanged": false }
  },
  "limits": {
    "requests_per_minute": 60,
    "max_payload_bytes": 262144,
    "max_iterations": 3,
    "sync_interval_seconds": 300
  },
  "surfaces": {
    "native": [],
    "mounted": []
  },
  "annotations": {
    "tool_scopes": {
      "flowhub.inventory":  "read_only",
      "flowhub.products":   "read_only",
      "flowhub.locations":  "read_only",
      "flowhub.sync":       "read_write",
      "flowhub.settings":   "read_only",
      "flowhub.analytics":  "read_only"
    }
  }
}
```

---

### 5.3 Shopify Sync MCP Server

| Field | Value |
|---|---|
| **Slug** | `shopify-sync` |
| **Class** | `WP_MCP_AI_Shopify_Sync_MCP_Server` |
| **Extends** | `WP_MCP_AI_Toolkit_Server_Base` |
| **Uses** | `WP_MCP_AI_Scheduled_Toolkit_Server_Trait` |
| **Ingestion surfaces** | None (tools-only server) |
| **Mounted surfaces** | None |
| **Candidate tools** | 5 tools |
| **Rate limit** | 60 req/min |
| **Max payload** | 512 KB (Shopify product data can be rich) |
| **Max iterations** | 3 |

#### Tools exposed via `tools/list`

```
shopify-sync.inventory              (read)   — CCT cache query, zero GraphQL cost
shopify-sync.products               (read)   — CCT cache query
shopify-sync.orders                 (read)   — CCT cache query (headers only)
shopify-sync.settings               (read)   — connection status, GraphQL cost report
shopify-sync.analytics              (read)   — CCT aggregate queries
```

#### Resources exposed via `resources/list`

```
nvoos://shopify-sync/inventory         — paginated multi-location inventory
nvoos://shopify-sync/products          — product catalog by SKU/type/vendor
nvoos://shopify-sync/orders            — recent orders (headers only)
nvoos://shopify-sync/sync-status       — last sync timestamp, GraphQL cost gauge
nvoos://shopify-sync/webhook-status    — registered webhooks, delivery health
```

#### Descriptor annotations

```json
{
  "slug": "shopify-sync",
  "name": "Shopify Sync",
  "version": "1.0.0",
  "description": "Shopify↔WooCommerce cache-first sync — AI assistants query inventory, products, orders, and analytics from local CCT cache with zero GraphQL API cost.",
  "capabilities": {
    "tools": { "listChanged": true },
    "resources": { "listChanged": true, "subscribe": false },
    "prompts": { "listChanged": false }
  },
  "limits": {
    "requests_per_minute": 60,
    "max_payload_bytes": 524288,
    "max_iterations": 3,
    "sync_interval_seconds": 300
  },
  "surfaces": {
    "native": [],
    "mounted": []
  },
  "annotations": {
    "tool_scopes": {
      "shopify-sync.inventory":  "read_only",
      "shopify-sync.products":   "read_only",
      "shopify-sync.orders":     "read_only",
      "shopify-sync.settings":   "read_only",
      "shopify-sync.analytics":  "read_only"
    }
  }
}
```

> **Note:** The Shopify Sync MCP server is distinct from any future Shopify live-API MCP server (which would expose `shopify_products`, `shopify_inventory`, `shopify_orders`, `shopify_customers`, `shopify_catalog` as live GraphQL tools). The two servers serve different use cases — sync tools are cache-first, bulk-analytics-oriented; live tools are real-time, mutation-capable.

---

### 5.4 EZuite ERP Sync MCP Server

| Field | Value |
|---|---|
| **Slug** | `ezuite` |
| **Class** | `WP_MCP_AI_EZuite_MCP_Server` |
| **Extends** | `WP_MCP_AI_Toolkit_Server_Base` |
| **Uses** | `WP_MCP_AI_Scheduled_Toolkit_Server_Trait` |
| **Ingestion surfaces** | None (tools-only server) |
| **Mounted surfaces** | None |
| **Candidate tools** | 6 tools |
| **Rate limit** | 60 req/min |
| **Max payload** | 256 KB |
| **Max iterations** | 3 |

#### Tools exposed via `tools/list`

```
ezuite.inventory                (read)   — CCT cache query, zero API cost
ezuite.erp_get_products         (read)   — pull products from EZuite with location filtering
ezuite.erp                      (read)   — list connections, test connection, invoke API actions
ezuite.settings                 (read)   — connection config, sync interval, field mapping
ezuite.sync                     (write)  — triggers Action Scheduler sync
ezuite.alerts                   (read)   — low-stock alerts, threshold configuration
```

#### Resources exposed via `resources/list`

```
nvoos://ezuite/inventory                — paginated inventory (CCT-backed)
nvoos://ezuite/products                 — product catalog with location data
nvoos://ezuite/sync-status              — last sync timestamp, status, row count
nvoos://ezuite/connection-status        — EZuite API health check
nvoos://ezuite/alerts                   — active low-stock alerts
```

#### Descriptor annotations

```json
{
  "slug": "ezuite",
  "name": "EZuite ERP Sync",
  "version": "1.0.0",
  "description": "EZuite ERP inventory → WooCommerce/WordPress sync via JetEngine CCT cache. AI assistants query inventory, products, and orders instantly — zero EZuite API calls per query.",
  "capabilities": {
    "tools": { "listChanged": true },
    "resources": { "listChanged": true, "subscribe": false },
    "prompts": { "listChanged": false }
  },
  "limits": {
    "requests_per_minute": 60,
    "max_payload_bytes": 262144,
    "max_iterations": 3,
    "sync_interval_seconds": 300
  },
  "surfaces": {
    "native": [],
    "mounted": []
  },
  "annotations": {
    "tool_scopes": {
      "ezuite.inventory":         "read_only",
      "ezuite.erp_get_products":  "read_only",
      "ezuite.erp":               "read_only",
      "ezuite.settings":          "read_only",
      "ezuite.sync":              "read_write",
      "ezuite.alerts":            "read_only"
    }
  }
}
```

---

## 6. Hardening Annotations — Existing 29-Server Fleet

### 6.1 `tool_scopes` Annotation

Per the MCP best-practice guidance on least-privilege, each server's descriptor should annotate which tools are read-only vs. read-write. Currently, the 29 existing servers do NOT include this annotation in their descriptors.

**Action:** Backfill `tool_scopes` annotations on all 29 existing servers. This is a low-effort, high-value change — each server's `candidate_tool_slugs()` already distinguishes read from write tools; we're simply exposing that distinction in the JSON descriptor.

Pattern to add to `WP_MCP_AI_Toolkit_Server_Base::get_descriptor()`:

```php
'annotations' => array(
    'tool_scopes' => $this->compute_tool_scopes(),
),
```

Where `compute_tool_scopes()` introspects each candidate tool's capability flags (already declared via `WP_MCP_AI_Tool_Capability_Flags_Interface`).

### 6.2 `sync_interval_seconds` Annotation

All servers backed by an Action Scheduler sync engine (FlowHub, Shopify Sync, EZuite, plus existing analytics servers) should advertise their sync interval in the descriptor.

**Action:** Apply `ScheduledToolkitServerTrait::annotate_sync_limits()` to applicable servers. Existing servers that would benefit: Analytics (if backed by scheduled aggregation), Cloudways (if backed by scheduled API polling).

### 6.3 Rate Limit Rationalization

Current per-server limits default to `requests_per_minute: 0` (unlimited). Industry best practice recommends explicit, domain-appropriate defaults:

| Server Category | Recommended Default RPM | Rationale |
|---|---|---|
| Read-heavy (CCT cache queries) | 60 | CCT reads are < 50ms; 60 RPM prevents abuse without impacting legitimate use |
| Write-heavy (sync triggers) | 10 | Sync triggers are expensive (API call + CCT write); throttle conservatively |
| Mixed (scheduler) | 30 | Schedule CRUD is low-frequency but can chain assistant_run dispatches |
| Admin (settings/config) | 10 | Configuration changes are rare and should be deliberate |

**Action:** Set explicit `requests_per_minute` defaults on all 33 servers matching these categories.

---

## 7. Implementation Phases

### Phase 8a — Foundation (Week 1, ~2 days)

| Task | Files | Effort |
|---|---|---|
| Create `trait-wp-mcp-ai-scheduled-toolkit-server.php` | 1 new file | 3 hours |
| Add `compute_tool_scopes()` to `WP_MCP_AI_Toolkit_Server_Base` | 1 edit | 2 hours |
| Add `annotations` key to `get_descriptor()` output | 1 edit | 1 hour |
| Update `WP_MCP_AI_Toolkit_Server_Interface` if needed | 0–1 edit | 30 min |
| Write `Test_Scheduled_Toolkit_Server_Trait` | 1 new test file | 2 hours |
| **Subtotal** | | **8.5 hours (~1 day)** |

### Phase 8b — Pro Scheduler Server (Week 1, ~1 day)

| Task | Files | Effort |
|---|---|---|
| Create `class-wp-mcp-ai-pro-scheduler-mcp-server.php` | 1 new file | 2 hours |
| Wire `research-schedule` ingestion surface to descriptor | in same file | 1 hour |
| Register in `mcp-servers-init.php` | 1 edit | 30 min |
| Verify "MCP Server" tab auto-appears on existing admin page | smoke test | 30 min |
| Write `Test_Pro_Scheduler_MCP_Server` | 1 new test file | 2 hours |
| **Subtotal** | | **6 hours (~1 day)** |

### Phase 8c — FlowHub MCP Server (Week 2, ~1 day)

| Task | Files | Effort |
|---|---|---|
| Create `class-wp-mcp-ai-flowhub-mcp-server.php` | 1 new file | 2 hours |
| Consume `ScheduledToolkitServerTrait` | in same file | 30 min |
| Register in `mcp-servers-init.php` | 1 edit | 30 min |
| Verify "MCP Server" tab auto-appears on FlowHub Toolkit admin page | smoke test | 30 min |
| Write `Test_FlowHub_MCP_Server` | 1 new test file | 2 hours |
| **Subtotal** | | **5.5 hours (~1 day)** |

### Phase 8d — Shopify Sync MCP Server (Week 2, ~1 day)

| Task | Files | Effort |
|---|---|---|
| Create `class-wp-mcp-ai-shopify-sync-mcp-server.php` | 1 new file | 2 hours |
| Consume `ScheduledToolkitServerTrait` | in same file | 30 min |
| Register in `mcp-servers-init.php` | 1 edit | 30 min |
| Verify "MCP Server" tab auto-appears on Shopify Sync admin page | smoke test | 30 min |
| Write `Test_Shopify_Sync_MCP_Server` | 1 new test file | 2 hours |
| **Subtotal** | | **5.5 hours (~1 day)** |

### Phase 8e — EZuite MCP Server (Week 2, ~1 day)

| Task | Files | Effort |
|---|---|---|
| Create `class-wp-mcp-ai-ezuite-mcp-server.php` | 1 new file | 2 hours |
| Consume `ScheduledToolkitServerTrait` | in same file | 30 min |
| Register in `mcp-servers-init.php` | 1 edit | 30 min |
| Verify "MCP Server" tab auto-appears on EZuite Toolkit admin page | smoke test | 30 min |
| Write `Test_EZuite_MCP_Server` | 1 new test file | 2 hours |
| **Subtotal** | | **5.5 hours (~1 day)** |

### Phase 8f — Hardening Backfill (Week 3, ~2 days)

| Task | Files | Effort |
|---|---|---|
| Backfill `tool_scopes` annotations on 29 existing servers | 29 edits (boilerplate) | 4 hours |
| Set explicit `requests_per_minute` defaults on all 33 servers | 33 edits (boilerplate) | 2 hours |
| Update `/.well-known/mcp` descriptor to include annotations | 1 edit | 1 hour |
| Update `class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php` Detail tab to render annotations | 1 edit | 2 hours |
| Write audit test: every registered server has non-null tool_scopes | 1 test | 1 hour |
| Write audit test: every registered server has explicit RPM default | 1 test | 1 hour |
| **Subtotal** | | **11 hours (~1.5 days)** |

### Phase 8g — Documentation & Tool Reference (Week 3, ~1 day)

| Task | Files | Effort |
|---|---|---|
| Update `docs/mcp-servers.md` with 4 new server entries | 1 edit | 1 hour |
| Update `docs/toolkits/flowhub-integration.md` — add MCP server section | 1 edit | 30 min |
| Update `docs/toolkits/shopify-sync-integration.md` — add MCP server section | 1 edit | 30 min |
| Update `docs/toolkits/ezuite-inventory-sync.md` — add MCP server section | 1 edit | 30 min |
| Create `docs/toolkits/pro-scheduler.md` — new toolkit doc with MCP server section | 1 new file | 2 hours |
| Update `docs/ROADMAP.md` — add Phase 8 entry to Pro Toolkits section | 1 edit | 30 min |
| Update `docs/project/proposals/README.md` — mark this proposal as in-progress | 1 edit | 15 min |
| **Subtotal** | | **5.25 hours (~1 day)** |

---

## 8. Test Coverage Plan

### 8.1 Contract Tests (for all 4 new servers)

Each new server must pass the existing contract test:

```bash
vendor/bin/phpunit addons/pro/tests/test-toolkit-server-contract.php
```

This validates:
- `get_slug()` returns a kebab-case string
- `get_name()` returns a non-empty translated string
- `get_description()` returns a non-empty translated string
- `get_version()` returns a valid semver string
- `candidate_tool_slugs()` returns an array
- `is_enabled()` returns a boolean
- `get_descriptor()` returns a valid MCP descriptor structure

### 8.2 Server-Specific Tests

```bash
# New test files:
vendor/bin/phpunit addons/pro/tests/test-pro-scheduler-mcp-server.php
vendor/bin/phpunit addons/pro/tests/test-flowhub-mcp-server.php
vendor/bin/phpunit addons/pro/tests/test-shopify-sync-mcp-server.php
vendor/bin/phpunit addons/pro/tests/test-ezuite-mcp-server.php
vendor/bin/phpunit addons/pro/tests/test-scheduled-toolkit-server-trait.php

# Updated test files:
vendor/bin/phpunit addons/pro/tests/test-pro-toolkit-mcp-servers-page.php  # 33 servers now
vendor/bin/phpunit addons/pro/tests/test-phase5-toolkit-mcp-servers.php     # tool_scopes annotation
```

### 8.3 Integration Tests

Each server's JSON-RPC endpoint must be tested:

| Route | Test |
|---|---|
| `GET /mcp-ai-pro/v1/mcp` | 33 servers in the fleet descriptor |
| `GET /mcp-ai-pro/v1/mcp/{slug}` | Each of the 4 new servers returns valid descriptor |
| `POST /mcp-ai-pro/v1/mcp/{slug}` | `initialize`, `ping`, `tools/list`, `resources/list` |

### 8.4 Annotation Audit Test

```php
public function test_all_servers_have_tool_scopes_annotation() {
    $servers = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get_all();
    foreach ( $servers as $server ) {
        $desc = $server->get_descriptor();
        $this->assertArrayHasKey( 'annotations', $desc );
        $this->assertArrayHasKey( 'tool_scopes', $desc['annotations'] );
    }
}
```

---

## 9. Effort Estimation

| Phase | Description | Effort | Cumulative |
|---|---|---|---|
| 8a | Foundation (trait + base class hardening) | ~1 day | 1 day |
| 8b | Pro Scheduler MCP Server | ~1 day | 2 days |
| 8c | FlowHub MCP Server | ~1 day | 3 days |
| 8d | Shopify Sync MCP Server | ~1 day | 4 days |
| 8e | EZuite MCP Server | ~1 day | 5 days |
| 8f | Hardening backfill (29 existing servers) | ~1.5 days | 6.5 days |
| 8g | Documentation + tool reference | ~1 day | 7.5 days |
| **Total** | | **~7.5 days (1.5 weeks)** | |

> **Staffing:** 1 developer, 1 reviewer. The work is highly parallelizable — Phases 8b–8e can be done by independent agents in parallel (disjoint write sets).

### Comparison with Prior Phases

| Phase | Servers Added | Effort | Servers/Day |
|---|---|---|---|
| Phase 1 (pilot) | 3 | ~3 days | 1.0 |
| Phase 2 (Tier-1) | 16 | ~5 days | 3.2 |
| Phase 6 (Tier-2) | 9 | ~3 days | 3.0 |
| **Phase 8 (this proposal)** | **4** | **~7.5 days** | **0.53** |

Phase 8 appears slower per-server because it includes:
- **Shared infrastructure** (trait + base-class hardening) — 1 day
- **Fleet-wide hardening backfill** (29 existing servers) — 1.5 days
- **Documentation** — 1 day

**Net new-server effort:** 4 days for 4 servers = 1.0 servers/day — consistent with Phase 1.

---

## 10. Success Metrics

| Metric | Target | Measurement |
|---|---|---|
| New MCP servers registered | 4 | `WP_MCP_AI_Toolkit_Server_Registry::get_all()` count = 33 |
| Contract test pass rate | 100% | `test-toolkit-server-contract.php` |
| Server-specific tests passing | 100% | 4 new test files, 2 updated test files |
| Annotation audit passing | 100% | New audit test — all 33 servers have `tool_scopes` |
| Admin "MCP Server" tabs functional | 4/4 | Manual smoke test on each toolkit settings page |
| JSON-RPC endpoints responding | 4/4 | `curl -X POST /wp-json/mcp-ai-pro/v1/mcp/{slug}` |
| `/.well-known/mcp` includes new servers | 33 servers listed | `curl /.well-known/mcp \| jq '.servers \| length'` |
| Zero regression on monolithic `/mcp-ai/v1/mcp` | Existing tests pass | Full test suite green |
| Documentation updated | 5 files updated/created | Manual review |

---

## 11. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Admin page tab wiring conflict** — Pro Scheduler already has MCP Server tab code; registering a new server class could double-render | Low | Medium | Audit `class-wp-mcp-ai-pro-schedule-toolkit-settings-page.php` to ensure it uses the auto-tab mechanism from `WP_MCP_AI_Toolkit_Settings_Base` rather than a hardcoded tab |
| **Tool slug collision** — flowhub/shopify-sync/ezuite tool slugs may collide with other toolkits' candidate slugs | Low | High | Run `WP_MCP_AI_Tool_Registry::get_tools()` and grep for slug overlaps before registration |
| **Action Scheduler dependency** — FlowHub/Shopify/EZuite MCP servers advertise sync status; but if Action Scheduler is not active (unlikely with WooCommerce), status will be stale | Very Low | Low | `ScheduledToolkitServerTrait` checks `function_exists('as_get_scheduled_actions')` before computing sync status |
| **CCT availability** — FlowHub/Shopify/EZuite depend on JetEngine CCT; if JetEngine is deactivated post-registration, resource URIs will 404 | Low | Medium | `ScheduledToolkitServerTrait` checks `class_exists('Jet_Engine')` and omits CCT-backed resources when unavailable |
| **Scope creep** — hardening backfill touches all 29 existing servers | Medium | Medium | Strictly enforce boilerplate-only changes: add `tool_scopes` annotation line, set explicit RPM — no other edits |

---

## 12. Decision Required

| Decision | Options | Recommendation |
|---|---|---|
| **Proceed with Phase 8?** | Yes / No / Defer | ✅ **Yes** — low-risk, high-value completion of an already-established pattern |
| **Create `ScheduledToolkitServerTrait`?** | Yes / Inline in each server / Defer | ✅ **Yes** — 3 servers share identical sync-engine pattern; trait avoids duplication |
| **Backfill `tool_scopes` on existing 29 servers?** | Yes / No / Defer to separate proposal | ✅ **Yes** — boilerplate change with security value; best done alongside new server additions |
| **Set explicit RPM defaults on all servers?** | Yes / No / Keep current (unlimited) | ✅ **Yes** — industry best practice; prevents abuse without impacting legitimate use |
| **Target version?** | v1.4.0 / v1.5.0 / v1.6.0 | 🟡 **v1.5.0** — v1.4.0 is already scoped for cross-platform extraction + Laravel adapter spike; Phase 8 fits naturally in the following minor |
| **Include Pro Workflow Builder in Phase 8?** | Yes / No / Defer to Phase 9 | 🟡 **Defer to Phase 9** — needs 6 days of prerequisite work (9 new MCP tools, toolkit toggle, settings page) before it can be promoted |

---

## 13. Future Phase 9 — Pro Workflow Builder

### Why Not Phase 8?

The **Pro Workflow Builder** (`nvoos-pro-workflow-builder`) is a production-grade React+ReactFlow visual workflow editor with save/load/delete/duplicate/export/rename/list/execute capabilities. However, it differs from the four Phase 8 candidates in one critical way:

| Criterion | Phase 8 Candidates | Pro Workflow Builder |
|---|---|---|
| MCP tools registered | ✅ 4–14 tools each | ❌ Zero — purely an admin UI |
| Toolkit settings toggle | ✅ `enable_*_toolkit` key | ❌ No toggle exists |
| Sync/scheduling engine | ✅ Action Scheduler or Schedule Manager | ❌ Admin AJAX only |
| Settings page extending `WP_MCP_AI_Toolkit_Settings_Base` | ✅ | ❌ Standalone React SPA |
| `manage_options` gating | Per-tool capability checks | Entire page gated |

**The Pro Workflow Builder is an admin authoring tool — it needs an AI-callable tool layer before it can be promoted to an MCP server.**

### Phase 9 Prerequisites

Before the Pro Workflow Builder can become an MCP server, these prerequisites must be completed:

| Prerequisite | What It Entails | Estimated Effort |
|---|---|---|
| **1. Create MCP tool classes** | `list_workflows`, `get_workflow`, `create_workflow`, `update_workflow`, `delete_workflow`, `run_workflow`, `get_workflow_status`, `duplicate_workflow`, `export_workflow` — 9 tools wrapping the existing AJAX handlers with canonical envelopes | ~2 days |
| **2. Register tools** | Add tool class map entries to `wp_mcp_ai_pro_register_tools()`, gated on new `enable_workflow_toolkit` toggle | ~2 hours |
| **3. Create toolkit settings page** | New page extending `WP_MCP_AI_Toolkit_Settings_Base` with Overview, Configuration, Tools tabs — auto-grows MCP Server tab once server class exists | ~1 day |
| **4. Add toolkit toggle** | `enable_workflow_toolkit` key in `wp_mcp_ai_settings` with admin UI | ~2 hours |
| **5. Audit capability gates** | Currently all workflow operations require `manage_options`; for MCP exposure, some read tools could use `edit_posts` | ~2 hours |
| **6. Create MCP server class** | `class-wp-mcp-ai-pro-workflow-builder-mcp-server.php` extending `WP_MCP_AI_Toolkit_Server_Base` | ~1 day |
| **7. Register server** | Require + register in `mcp-servers-init.php` | ~30 min |
| **8. Tests** | Contract test + server-specific test + tool tests | ~1.5 days |
| **Total prerequisite effort** | | **~6 days** |

### Phase 9 Server Specification (Preview)

| Field | Value |
|---|---|
| **Slug** | `workflow-builder` |
| **Class** | `WP_MCP_AI_Pro_Workflow_Builder_MCP_Server` |
| **Ingestion surfaces** | None (tools-only server) |
| **Candidate tools** | 9 tools (see prerequisite #1) |
| **Rate limit** | 30 req/min (workflow CRUD is low-frequency) |
| **Max payload** | 256 KB (workflow DAGs can be large) |
| **Max iterations** | 3 |

#### Tools (after prerequisite completion)

```
workflow-builder.list_workflows      (read)   — list all saved workflows with metadata
workflow-builder.get_workflow         (read)   — get single workflow by ID
workflow-builder.create_workflow      (write)  — create new workflow from DAG JSON
workflow-builder.update_workflow      (write)  — update existing workflow
workflow-builder.delete_workflow      (write)  — delete workflow by ID
workflow-builder.duplicate_workflow   (write)  — clone workflow with new ID
workflow-builder.export_workflow      (read)   — export workflow as JSON
workflow-builder.run_workflow         (write)  — execute a saved workflow
workflow-builder.get_workflow_status  (read)   — get execution status of last run
```

#### Resources (after prerequisite completion)

```
nvoos://workflow-builder/workflows           — paginated workflow list
nvoos://workflow-builder/workflows/{id}      — single workflow DAG + metadata
nvoos://workflow-builder/templates           — installable workflow templates
nvoos://workflow-builder/presets             — registered workflow presets catalog
nvoos://workflow-builder/executions/{id}     — execution history for a workflow
```

### Recommendation

| Decision | Options | Recommendation |
|---|---|---|
| **Include in Phase 8?** | Yes / No | ❌ **No** — 6 days of prerequisite work would bloat Phase 8 to 2.5+ weeks |
| **Defer to Phase 9?** | Yes / No | ✅ **Yes** — target v1.6.0; the 6-day prerequisite effort is self-contained and can be done by a dedicated agent in parallel with Phase 8 hardening |
| **Add to roadmap now?** | Yes / No | ✅ **Yes** — document the candidate so it's not forgotten; the existing React SPA is already production-grade |

---

## Appendices

### A. Server Fleet — Before and After

| Phase | Count | Servers |
|---|---|---|
| Phase 1 (pilot) | 3 | CRM, Healthcare, Architectural Design |
| Phase 2 (Tier-1) | 16 | AI Tool Builder, Calendar Booking, CRE Debt, DJ Management, Document Generation, ECA, Ecommerce, Financial Planner, Image Production, Law Firm, Media, Multilingual, Project Management, Regulatory Registration, Social Media, Video Production |
| DietPi | 1 | DietPi Pro Toolkit |
| Phase 6 (Tier-2) | 9 | Analytics, Architect Agent, Chat Channels, Cloudways, Comic Creation, Extended Cognition, Healthcare Imaging, Healthcare Wellness, Site Creator |
| **Total before Phase 8** | **29** | |
| **Phase 8 (this proposal)** | **4** | Pro Scheduler, FlowHub, Shopify Sync, EZuite |
| **Total after Phase 8** | **33** | |
| **Phase 9 (future)** | **1** | Pro Workflow Builder (prerequisites: 9 new MCP tools + toolkit toggle + settings page) |
| **Total after Phase 9** | **34** | |

### B. Files Changed — Summary

| Type | Count | Details |
|---|---|---|
| New server classes | 4 | `servers/class-wp-mcp-ai-{toolkit}-mcp-server.php` |
| New trait | 1 | `trait-wp-mcp-ai-scheduled-toolkit-server.php` |
| Edited bootstrap | 1 | `mcp-servers-init.php` (4 requires + 4 register calls) |
| Edited base class | 1 | `class-wp-mcp-ai-toolkit-server-base.php` (annotations key) |
| Hardening backfill | 29 | One annotation line + one RPM default per server |
| Admin page update | 1 | `class-wp-mcp-ai-pro-toolkit-mcp-servers-page.php` (render annotations) |
| Well-known update | 1 | `class-wp-mcp-ai-pro-well-known-mcp.php` (include annotations) |
| New test files | 5 | 4 server tests + 1 trait test |
| Updated test files | 2 | Contract + fleet audit |
| New docs | 1 | `docs/toolkits/pro-scheduler.md` |
| Updated docs | 5 | mcp-servers.md, 3 toolkit docs, ROADMAP.md, proposals/README.md |
| **Total files touched** | **51** | |

### C. Industry References

- [MCP Best Practices — Architecture & Implementation Guide](https://modelcontextprotocol.info/docs/best-practices/) — focused-domain servers, least-privilege, strong input validation
- [MCP Best Practice](https://mcp-best-practice.github.io/mcp-best-practice/best-practice/) — OAuth 2.0, per-tool authorization, error-first validation
- [MCP Server Patterns for Enterprise AI Agents](https://www.digitalapplied.com/blog/mcp-server-patterns-enterprise-ai-agents) — authentication, rate limiting, tool registries, multi-tenancy
- [PhialsBasement/scheduler-mcp](https://github.com/PhialsBasement/scheduler-mcp) — reference MCP scheduler implementation (cron expressions, execution history)
- [Top 7 MCP Servers for WordPress Automation](https://agentpress.dev/top-7-mcp-servers-for-wordpress-automation-in-2025/) — WordPress-specific MCP server patterns
- [Action Scheduler](https://actionscheduler.org/) — scalable job queue for WordPress, used by FlowHub/Shopify/EZuite sync engines
- [Shopify Admin GraphQL API](https://shopify.dev/docs/api/admin-graphql) — API versioning, cost model, webhook HMAC verification
- [FlowHub API](https://www.flowhub.com/) — POS/inventory API for cannabis retail
