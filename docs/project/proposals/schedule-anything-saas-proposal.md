# Schedule Anything SaaS — Comprehensive Proposal

> **Status:** ✅ Implemented (v1.1.29) — `addons/schedule-anything-platform/`, `addons/schedule-anything-spa/`, and `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php` all exist. Core platform and SPA frontend delivered.
>
> **Last Updated:** June 11, 2026
> **Author:** NV Digital Solutions

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Platform Vision](#2-platform-vision)
3. [Leveraged Assets — What the NV oOS Monorepo Already Ships](#3-leveraged-assets--what-the-nv-oos-monorepo-already-ships)
4. [Architecture Deep-Dive](#4-architecture-deep-dive)
5. [All 30 Toolkits as SaaS Features](#5-all-30-toolkits-as-saas-features)
6. [Multi-Tenancy Strategy](#6-multi-tenancy-strategy)
7. [Risk Mitigation — Full Control Edition](#7-risk-mitigation--full-control-edition)
8. [Changes Needed in the NV oOS Codebase](#8-changes-needed-in-the-nv-oos-codebase)
9. [MVP Phased Roadmap](#9-mvp-phased-roadmap)
10. [Cost Model & Unit Economics — Platform Owner View](#10-cost-model--unit-economics--platform-owner-view)
11. [Scaling Strategy](#11-scaling-strategy)
12. [Technical Implementation Details](#12-technical-implementation-details)
13. [Go-to-Market Strategy](#13-go-to-market-strategy)
14. [Appendices](#14-appendices)

---

## 1. Executive Summary

**Schedule Anything** is a horizontal AI-powered automation SaaS — a commercial offering built on NV oOS — that lets businesses schedule, orchestrate, and automate work across all 30 Pro toolkits from a single unified React dashboard. Appointments, financial reports, social media campaigns, document generation, CRM workflows, legal filings, healthcare scheduling, e-commerce automation — every toolkit becomes a schedulable, automatable SaaS feature.

Because we own the platform, there is zero licensing friction, full source access, and the ability to modify plugin internals to optimize for multi-tenant SaaS operations.

**Key numbers:**
- **30** professional toolkits (already built in NV oOS Pro)
- **539+** AI tools (250+ base + 289+ pro)
- **50+** pre-built schedule presets across 21 toolkits, one-click installable
- **5** schedule types: task, workflow, assistant_run, channel_broadcast, workflow_builder
- **13** AI providers already integrated
- **~80%** of backend infrastructure already exists in this monorepo
- **30-45 weeks** of equivalent greenfield development time already invested
- **12-16 weeks** to MVP launch with a small team (2-3 engineers)

---

## 2. Platform Vision

### 2.1 What Tenants Can Do

A tenant (business owner, agency, enterprise) logs into their Schedule Anything dashboard and can:

1. **Create scheduled workflows visually** — drag tools from any toolkit, chain them into DAG pipelines, set triggers (cron, webhook, event), deploy with one click.

2. **Activate pre-built presets** — from the preset library of 50+ recipes: "Daily Sales Report," "Abandoned Cart Recovery," "Weekly Social Media Digest," "Patient Checkup Reminders," "Trust Account Reconciliation" — all built on the existing `WP_MCP_AI_Pro_Schedule_Presets` registry.

3. **Use natural language to build schedules** — "Send me a weekly report of my top-selling products and post it to Slack every Monday at 9 AM." The AI assistant (already built) interprets the intent and creates the workflow using the existing `assistant_run` schedule type.

4. **Offer public booking to their customers** — a Calendly-style booking portal powered by the Calendar Booking toolkit (`mcp_appointment`, `mcp_service`, `mcp_staff` CPTs).

5. **Monitor everything** — unified dashboard showing all scheduled runs, execution history, structured result envelopes, errors, and performance metrics — all already stored by the Schedule Manager's history ring buffer and result envelope system.

6. **Bring their own AI keys** (BYOK, free for us) or use NV oOS Cloud (7% markup revenue stream for us).

### 2.2 Why This Is a Strong NV oOS Product

- **It monetizes the entire toolkit surface.** Instead of selling Pro licenses one at a time, we sell a SaaS subscription that gives access to all 30 toolkits. Higher ARPU, recurring revenue.
- **It demonstrates NV oOS at scale.** A multi-tenant, headless WordPress deployment proves the platform's enterprise readiness.
- **It creates a compounding preset library.** Every tenant's custom workflow becomes a potential new preset. The preset library grows with usage.
- **The AI layer is a differentiator no competitor can match.** 13 providers, AI-powered workflow creation, AI-optimized scheduling — this is not a Calendly clone.

---

## 3. Leveraged Assets — What the NV oOS Monorepo Already Ships

### 3.1 Backend Infrastructure

| Asset | Location in Monorepo | What It Provides |
|---|---|---|
| **Schedule Manager** | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php` (3,235 lines) | Production scheduling engine: `create_schedule()`, `update_schedule()`, `delete_schedule()`, 5 schedule types, retry (0-5), timeout, callback webhook with HMAC, failure notifications via email + 6 chat platforms, run history ring buffer (50 entries), structured result envelopes, iCal/CSV export, 8 custom WP-Cron intervals |
| **Schedule Presets** | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php` | 50+ pre-built automation recipes with `install_preset()` API. Categories: content, monitoring, reporting, communication, maintenance, marketing, business, lead_intake, support |
| **Tool Registry** | `includes/tools/` + `addons/pro/includes/tools/` | 539+ tool implementations with JSON Schema definitions, capability gates, availability checks |
| **REST API** | `includes/rest/` + `addons/pro/includes/rest/` | `mcp-ai/v1/` (chat, tools, SSE, MCP, A2A), `mcp-ai-pro/v1/` (schedules, CRM, skills, NV Cloud) |
| **Authentication** | Base REST controllers | 4 auth modes: nonce, bearer, Auth0, guest — rate-limited, capability-gated |
| **Cloudways API Client** | `addons/pro/includes/cloudways/class-wp-mcp-ai-cloudways-client.php` | OAuth-authenticated client for Cloudways API v2 — servers, apps, provisioning |
| **Cloudways Dashboard SPA** | `addons/cloudways-dashboard/` | React SPA for server/app management, async provisioning with Action Scheduler (30s poll, 60 retry max) |
| **SaaS Controller** | `addons/saas-controller/` | HITL-gated Cloudflare Worker deployment, Stripe webhook verification (HMAC-SHA256), encrypted credential store (AES-256-CBC), audit log |
| **Cloud Worker** | `addons/cloud-worker/` | Production SaaS backend: inference proxy (OpenAI-compatible), D1 ledger with atomic transactions, Stripe Checkout top-ups, connect tokens with SHA-256 + site URL binding |
| **NV Cloud Service** | `addons/pro/includes/services/class-wp-mcp-ai-nv-cloud-service.php` | Plugin-side billing: encrypted connect-token storage, balance cache, 7% markup, auto-top-up |
| **13 AI Providers** | `includes/providers/` + `addons/pro/includes/providers/` | OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, Ollama, LM Studio |

### 3.2 Frontend Assets

| Asset | Location | What It Provides |
|---|---|---|
| **Chat SPA** | `addons/chat-spa/` | React + Vercel AI SDK (`useChat`) with SSE streaming adapter, tool-invocation cards, transcript sidebar, shortcode + Gutenberg block — proven React-on-WordPress auth and data-fetching pattern |
| **Toolkit Shell** | `addons/toolkit-shell/` | Manifest-driven React SPA — auto-renders list/table/kanban from any toolkit's REST data. One JSON manifest = instant admin UI |
| **Cloudways Dashboard SPA** | `addons/cloudways-dashboard/src/` | React TypeScript SPA with HashRouter, ErrorBoundary, skeleton loading, 9 pages, Velzon design system, `@tanstack/react-query` polling |
| **SaaS Controller Admin UI** | `addons/saas-controller/assets/src/` | React admin UI with `zod` validation, `diff` preview, `@wordpress/scripts` build |

### 3.3 Calendar Booking Data Model

| CPT | Slug | Key Meta |
|---|---|---|
| **Appointment** | `mcp_appointment` | client_name, client_email, client_phone, start_time, end_time, service_id, staff_id, status, google_event_id, outlook_event_id |
| **Service** | `mcp_service` | duration, price, buffer_before, buffer_after, max_per_day, category, color |
| **Staff** | `mcp_staff` | email, phone, services, working_hours (JSON), timezone, calendar IDs, color |

### 3.4 Schedule Manager — Schedule Types

| Type | Constant | Behavior |
|---|---|---|
| **Task** | `TYPE_TASK` | Fires a WP action hook on a cron interval |
| **Workflow** | `TYPE_WORKFLOW` | Executes a sequence of tool calls in order |
| **Assistant Run** | `TYPE_ASSISTANT_RUN` | Sends a message to an AI assistant (configurable context + max agentic iterations) |
| **Channel Broadcast** | `TYPE_CHANNEL_BROADCAST` | Broadcasts to Slack/Teams/Discord/Telegram/Messenger/WhatsApp |
| **Workflow Builder** | `TYPE_WORKFLOW_BUILDER` | Runs a saved Pro Workflow Builder DAG |

**Custom cron intervals:** every_5_minutes, every_15_minutes, every_30_minutes, every_2_hours, every_6_hours, every_12_hours, weekly, monthly.

---

## 4. Architecture Deep-Dive

### 4.1 System Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                     scheduleanything.com                          │
│                     React SPA (Vite + TypeScript)                 │
│                                                                  │
│  ┌──────────────────┐  ┌──────────────────┐  ┌────────────────┐ │
│  │  Public Booking   │  │  Tenant Admin    │  │  Unified        │ │
│  │  Portal           │  │  Dashboard       │  │  Schedule       │ │
│  │  (Calendly-style) │  │  (Toolkit mgmt,  │  │  Builder        │ │
│  │                   │  │   analytics,     │  │  (React Flow    │ │
│  │                   │  │   billing)       │  │   DAG editor)   │ │
│  └────────┬─────────┘  └────────┬─────────┘  └───────┬────────┘ │
│           └─────────────────────┼─────────────────────┘          │
│                    REST API Client Layer                          │
└─────────────────────────────────┼────────────────────────────────┘
                                  │ HTTPS
                    ┌─────────────┼──────────────────┐
                    │             ▼                  │
                    │  ┌───────────────────────────┐ │
                    │  │  Tenant Router             │ │
                    │  │  (Cloudflare Worker)       │ │
                    │  │  subdomain → tenant origin │ │
                    │  │  rate limiting + logging   │ │
                    │  └───────────┬───────────────┘ │
                    │       ┌──────┼──────┐          │
                    │       ▼      ▼      ▼          │
                    │  ┌────────┐┌────────┐┌────────┐│
                    │  │Tenant A││Tenant B││Tenant C││
                    │  │WP HW   ││WP HW   ││WP HW   ││
                    │  │NV oOS  ││NV oOS  ││NV oOS  ││
                    │  │Base+Pro││Base+Pro││Base+Pro││
                    │  └────────┘└────────┘└────────┘│
                    │   Cloudways Managed Hosting     │
                    └────────────────────────────────┘
                                  │
                    ┌─────────────┼──────────────────┐
                    │  SaaS Billing (CF Worker)       │
                    │  D1 Ledger + Stripe Subscriptions│
                    │  Usage metering + provisioning  │
                    └────────────────────────────────┘
```

### 4.2 Request Flow

```
1. User visits tenant-a.scheduleanything.com
2. React SPA loads, reads tenant slug from subdomain
3. SPA calls GET /wp-json/mcp-ai-pro/v1/schedules
   → Tenant Router maps tenant-a → WP instance origin
   → WP authenticates (X-WP-Nonce or Bearer)
   → Returns tenant-a's schedules (naturally scoped per Multisite subsite)
4. User creates schedule in visual builder
   → POST /wp-json/mcp-ai-pro/v1/schedules
   → WP_MCP_AI_Pro_Schedule_Manager::create_schedule()
   → wp_schedule_event() registered on tenant's subsite
5. At scheduled time, WP-Cron fires wp_mcp_ai_pro_schedule_exec
   → dispatch() → routes to correct type handler → executes
   → Records run history + result envelope
   → Fires callback webhook if configured
```

### 4.3 Tenant Router (Cloudflare Worker)

```typescript
export default {
  async fetch(request: Request, env: Env) {
    const url = new URL(request.url);
    const tenantSlug = url.hostname.split('.')[0];

    const origin = await env.TENANT_KV.get(tenantSlug);
    if (!origin) return new Response('Tenant not found', { status: 404 });

    const tenantUrl = new URL(url.pathname + url.search, origin);
    const modifiedRequest = new Request(tenantUrl.toString(), {
      method: request.method,
      headers: request.headers,
      body: request.body,
    });
    modifiedRequest.headers.set('X-Tenant-Slug', tenantSlug);

    // Per-tenant rate limiting
    const { ok } = await env.RATE_LIMITER.limit({ key: tenantSlug });
    if (!ok) return new Response('Rate limit exceeded', { status: 429 });

    return fetch(modifiedRequest);
  }
};
```

---

## 5. All 30 Toolkits as SaaS Features

### 5.1 Tiering Strategy

| Tier | Price/Month | Toolkits Included | Target Audience |
|---|---|---|---|
| **Starter** | $49 | 5 core: Calendar Booking, Project Management, CRM, Analytics, Social Media | Solo professionals, small businesses |
| **Professional** | $149 | 15: Starter + Document Gen, Multilingual, Media, Image Production, Video Production, Financial Planner, E-commerce, Chat Channels, Password Vault, Skills Manager | Agencies, growing businesses |
| **Enterprise** | $499 | All 30 + dedicated WP instance + priority support + SLA | Large orgs, regulated industries |

### 5.2 Per-Toolkit Scheduling Capabilities

| # | Toolkit | Tools | Key Scheduled Actions | Presets Ready |
|---|---|---|---|---|
| 1 | Document Generation | PDF/Word/Excel | Scheduled reports, invoice generation | ✅ |
| 2 | Multilingual Content | 10 | Periodic translation, translation memory refresh | ✅ |
| 3 | Media (sharp) | Image processing | Batch optimization, thumbnail regeneration | — |
| 4 | Image Production | AI image gen | Scheduled brand asset creation | ✅ |
| 5 | Video Production | 12 | Automated editing, subtitle generation | ✅ |
| 6 | Project Management | Full system | Milestone deadlines, task reminders, .ics exports | — |
| 7 | CRM & Email Marketing | 70+ | Lead follow-ups, drip campaigns, pipeline nudges | ✅ |
| 8 | Financial Planner | 24 | Budget reports, portfolio rebalancing, retirement projections | ✅ |
| 9 | E-commerce | 20 | Inventory checks, cart recovery, price monitoring, sales reports | ✅ (5 presets) |
| 10 | Social Media | 15 | Content scheduler, cross-platform syndication, engagement reports | ✅ (5 presets) |
| 11 | Analytics | 12 | Traffic reports, performance digests, conversion funnels, SEO checks | ✅ (5 presets) |
| 12 | Site Creator | Advanced | Automated page builds, template deployments | ✅ |
| 13 | Architect Agent | AI coding | Scheduled code audits, automated refactoring | ✅ |
| 14 | Architectural Design | Professional | Milestone deadlines, spec review reminders | ✅ |
| 15 | AI Tool Builder | In planning | Tool generation pipelines, testing schedules | ✅ |
| 16 | Calendar Booking | 15 | Appointments, availability, reminders, Google/Outlook sync | ✅ |
| 17 | DJ Management | Full system | Event scheduling, playlist rotation, equipment tracking | ✅ |
| 18 | Health & Wellness | EHR system | Patient scheduling, prescription refills, insurance tracking | ✅ |
| 19 | Healthcare Imaging | DICOM | Study processing, audit reports | — |
| 20 | Regulatory Registration | Compliance | License renewal, permit expiration, compliance reports | ✅ |
| 21 | Law Firm | 64 | Court deadlines, filing reminders, trust reconciliation (IOLTA) | — |
| 22 | CRE Debt | 57 | Payment alerts, rate-reset notifications, covenant checks (CREFC) | — |
| 23 | Places (turf.js) | Geospatial | Location-based scheduling, proximity alerts | — |
| 24 | Quiz System | Math (KaTeX) | Scheduled assessments, automated grading | — |
| 25 | ECA Management | Schools | Club schedules, attendance tracking, timetable conflicts | — |
| 26 | Password Vault | AES-256-GCM | Credential rotation reminders, security audits | — |
| 27 | Extended Cognition | Sensor inputs | Sensor data collection schedules | — |
| 28 | Chat Channels | Multi-platform | Scheduled broadcasts, team updates | ✅ |
| 29 | Skills Manager | agentskills.io | Skill update checks, version audits | — |
| 30 | Vector Storage Pro | RAG/embeddings | Knowledge-base refresh, reindexing | — |

---

## 6. Multi-Tenancy Strategy

### 6.1 Recommended: WordPress Multisite

**Why Multisite is the right choice for us:**

| Factor | Multisite | Per-Tenant Instance |
|---|---|---|
| **Code deployment** | One plugin update = all tenants | Per-instance update cycle |
| **Provisioning speed** | `wpmu_create_blog()` — instant | Cloudways API → 5-15 min |
| **Cost/tenant** | ~$2-5/mo (shared server) | ~$10/mo (separate droplet) |
| **Data isolation** | Natural per-subsite table scoping | Physical DB separation |
| **Plugin management** | Network-activate Base+Pro once | Install per instance |
| **Scaling** | ~500-1,000 tenants/server | Unlimited horizontal (but expensive) |

**Hybrid approach:** Enterprise tenants (Law Firm, Healthcare) get dedicated WP instances on Cloudways for physical data isolation. Standard tenants share Multisite networks. Both are provisioned through the same pipeline.

### 6.2 Tenant Provisioning Flow

```
1. Prospect visits scheduleanything.com → signs up → selects tier
2. Stripe Checkout → subscription created → webhook fires
3. Provisioning Worker:
   a. Multisite tier: wpmu_create_blog() on target network → instant
   b. Dedicated tier: Cloudways API → create server → create app → install WP
4. Network-activate NV oOS Base + Pro on new subsite
5. Seed tenant defaults:
   - Enable toolkit feature flags per tier (via wp_mcp_ai_settings option)
   - Install tier-appropriate schedule presets via WP_MCP_AI_Pro_Schedule_Presets::install_preset()
   - Create default AI assistant
   - Configure WP admin user with tenant admin role
6. Register tenant in Cloud Worker D1: tenant slug → WP origin URL mapping
7. Send welcome email with login link
8. Tenant logs in → sees pre-configured dashboard with their schedules
```

### 6.3 Data Isolation — Already Handled

In WordPress Multisite, `get_option()` and CPT queries are naturally scoped per subsite. The Schedule Manager stores schedules in the `wp_mcp_ai_pro_schedules` option — each subsite has its own. CPT data (`mcp_appointment`, `mcp_service`, `mcp_staff`) is also automatically scoped. **No code changes needed** for data isolation at the option/CPT layer.

For shared services (audit logs, usage tracking), prefix option keys with `blog_id` or store in a network-level option with a `blog_id` column.

---

## 7. Risk Mitigation — Full Control Edition

### 7.1 Risk: Licensing — ✅ ELIMINATED

**We own NV oOS.** There is no third-party licensing cost, no negotiation, and no per-tenant fee to anyone else. The Pro addon is our own commercial product. We can deploy it to as many tenant instances as we want at zero marginal cost.

This removes the single biggest cost variable from the unit economics. Every dollar of tenant subscription revenue (minus hosting + Stripe fees) is gross profit.

### 7.2 Risk: WordPress Write Scaling

**Severity:** 🟡 Medium — becomes relevant at 1,000+ active tenants

**Root cause:** Each scheduled workflow run creates WP posts. At volume, `wp_posts`/`wp_postmeta` EAV tables bottleneck on writes.

**Mitigation — because we own the code, we fix it at the source:**

#### Phase 1 (MVP — 0-500 tenants): No changes needed
- Redis object cache (Cloudways provides this)
- WP 6.9+ salted query cache for post/term/user queries
- Schedule Manager's ring buffer already prevents unbounded option growth

#### Phase 2 (500-2,000 tenants): Optimize in-place
- **Add custom indexes to postmeta** for high-frequency appointment queries:
  ```sql
  ALTER TABLE wp_postmeta ADD INDEX idx_appt_time (meta_key, meta_value(20));
  ```
- **Migrate schedule execution from WP-Cron to Action Scheduler** (already a dependency). Better concurrency, async processing, no HTTP-trigger dependency.
- **Batch schedule dispatch:** When multiple schedules fire at the same minute, batch them into a single PHP process instead of spawning N separate processes.
- **Read replicas** for report-heavy tenants (Cloudways supports this).

#### Phase 3 (2,000-10,000 tenants): Custom table extraction
- **Create dedicated `wp_mcp_appointments` table** with proper typed columns (not EAV) for the Calendar Booking hot path. Add a `WP_MCP_AI_Appointment_Repository` class that reads from this table for availability queries while continuing to use CPTs for the admin UI.
- **External job queue:** Move schedule execution to Cloudflare Queues or a Redis-backed queue. WordPress handles configuration; the queue worker handles execution.
- This is a natural evolution — we already have the Cloud Worker pattern for extracting compute from WordPress.

#### Phase 4 (10,000+ tenants): WordPress as admin/config layer
- Schedule execution microservice (Go or Rust, deployed on Cloudflare Workers or Fly.io)
- Event-driven: `appointment.booked` → message bus → downstream services
- Read-optimized search index (Meilisearch) for sub-100ms availability lookups
- WordPress stays as the configuration store, admin UI, and CPT management layer

### 7.3 Risk: Toolkit Stability

**Severity:** 🟡 Medium — some toolkits are recently built (Phase 2.x)

**Mitigation — we own the test suites and can fix bugs directly:**

1. **Pre-MVP audit (Week 1-2):** Run the full PHPUnit suite across all 30 toolkits. Document gaps. Fix bugs in our own code.
2. **Graduated rollout:** MVP enables 5 core toolkits. Add 5 more each month. Domain-specific toolkits (Law Firm, CRE Debt, Healthcare) get dedicated QA + domain-expert review before enabling.
3. **Canary deployments:** Push Pro updates to 5% of tenants first. Monitor error rates for 24h. Roll out fully. Per-toolkit feature flags allow instant disable of any buggy toolkit across all tenants.
4. **Domain-expert review gates for compliance toolkits:**
   - Law Firm: ABA Model Rules / IOLTA compliance
   - CRE Debt: CREFC / MBA / CFA standard alignment
   - Healthcare: HIPAA audit before enabling for healthcare tenants
   - Financial Planner: Calculation verification against known benchmarks

### 7.4 Risk: AI Provider Costs

**Severity:** 🟡 Medium — pass-through risk if tenants abuse managed AI

**Mitigation — the Cloud Worker already has the right patterns:**

1. **Pre-paid wallet model (already built):** D1 ledger + Stripe Checkout + atomic balance enforcement. Extend for tenant subscription billing.
2. **Hard spending caps:** `max_ai_spend_per_day` and `max_ai_spend_per_month` per tenant, enforced at the Cloud Worker level (not bypassable from WordPress).
3. **Model restrictions per tier:** Starter → cheap models only. Pro → mid-range. Enterprise → all models.
4. **BYOK default:** Tenants bring their own keys = zero cost exposure for us. NV oOS Cloud is opt-in.
5. **Fraud detection:** Monitor for anomalous spend patterns. Auto-pause on 10x spike. 3D Secure for top-ups over $100.

### 7.5 Risk: Cloudways API Rate Limits

**Severity:** 🟢 Low — low-frequency operation, existing async pattern

**Mitigation:**

1. **Multisite is primary strategy.** `wpmu_create_blog()` is instant and doesn't touch the Cloudways API. Only Enterprise dedicated-instance tenants trigger Cloudways provisioning.
2. **Async provisioning with queue:** Reuse the Cloudways Dashboard addon's Action Scheduler pattern (30s poll, 60 retry max). Push to a queue. Process at controlled rate.
3. **Pre-provisioned pool:** Keep 5-10 pre-built WP instances warm. Assign instantly on Enterprise signup. Background job replenishes.

---

## 8. Changes Needed in the NV oOS Codebase

Because we own the platform, we can make surgical changes to optimize for multi-tenant SaaS operations. These are **low-risk, backward-compatible changes** — nothing breaks existing single-site Pro users.

### 8.1 Schedule Manager Enhancements

| Change | File | Rationale |
|---|---|---|
| Add `tenant_id` to schedule records | `class-wp-mcp-ai-pro-schedule-manager.php` | For cross-tenant analytics. In Multisite, `get_current_blog_id()` is the de-facto tenant_id. |
| Add `schedule_updated` webhook | `class-wp-mcp-ai-pro-schedule-manager.php` | Notify the Cloud Worker when a schedule changes, for usage metering. |
| Add batch dispatch mode | `class-wp-mcp-ai-pro-schedule-manager.php` | When N schedules fire at the same minute, run them in a single process to reduce overhead. |
| Expose schedules via REST | `addons/pro/includes/rest/` (new routes) | Full CRUD for schedules at `mcp-ai-pro/v1/schedules` — needed for the React SPA. |

### 8.2 Calendar Booking Toolkit Enhancements

| Change | File | Rationale |
|---|---|---|
| Add custom `wp_mcp_appointments` table | New file: `class-wp-mcp-ai-appointment-repository.php` | Typed columns for fast availability queries. Writes go to both CPT and custom table (dual-write). Reads use custom table for lookups, CPT for admin UI. |
| Add public REST endpoints | New file: `class-wp-mcp-ai-booking-public-controller.php` | `GET /bookings/slots`, `POST /bookings` — unauthenticated, rate-limited. Needed for public booking portal. |
| Add Stripe payment integration | `class-wp-mcp-ai-tool-create-appointment.php` | Optional `payment_required` flag on services. Creates Stripe PaymentIntent on booking. |

### 8.3 Multi-Tenant Infrastructure

| Change | File/Location | Rationale |
|---|---|---|
| New plugin: `schedule-anything-platform` | `addons/saas-controller/` (extend) | Tenant provisioning, usage heartbeat, cross-tenant analytics. Sits alongside NV oOS Base+Pro. |
| Extend Cloud Worker for subscriptions | `addons/cloud-worker/` | Add `tenants` and `tenant_usage` D1 tables. Stripe subscription webhook handlers. |
| Tenant router Cloudflare Worker | New: `addons/tenant-router/` | Subdomain-based routing to WP instances. KV-backed tenant→origin mapping. |
| Per-tenant toolkit activation API | `class-wp-mcp-ai-pro-toolkit-integration.php` | REST endpoint to toggle toolkit feature flags per tenant. |

### 8.4 Preset Library Expansion

| Change | File | Rationale |
|---|---|---|
| Add 25+ new presets | `class-wp-mcp-ai-pro-schedule-presets.php` | Cover the remaining toolkits without presets: Project Management, Law Firm, CRE Debt, Places, Quiz, ECA, Password Vault, Extended Cognition, Vector Storage, Healthcare Imaging. |
| Add preset categories | `class-wp-mcp-ai-pro-schedule-presets.php` | New categories: legal, healthcare, education, finance, real_estate, security |

### 8.5 No Changes Needed (Already SaaS-Ready)

| Component | Why It Works As-Is |
|---|---|
| **Schedule Manager** | Option-based storage is naturally scoped per Multisite subsite. The dispatch system works independently per site. |
| **Tool Registry** | Tools are discovered dynamically. Each subsite's enabled toolkits determine available tools. |
| **REST API** | Existing auth (nonce + bearer) works per-site. Rate limiting is per-request. |
| **13 AI Providers** | Provider keys are stored per-site via `wp_mcp_ai_settings`. Each tenant configures their own. |
| **Chat Channels** | Webhook controllers are already designed for multi-tenant operation (per-channel credentials). |
| **Cloudways API Client** | Already uses stored credentials. Works for provisioning Enterprise tenants. |
| **SaaS Controller** | The drift detection, audit log, and Stripe webhook verification are already production-grade. |

---

## 9. MVP Phased Roadmap

### Phase 0: Foundation (Weeks 1-2)

| Task | Effort | Deliverable |
|---|---|---|
| Set up Cloudways Multisite staging environment | 0.5 weeks | Staging WP Multisite with Base+Pro network-activated |
| Deploy Cloud Worker to staging | 0.5 weeks | api-staging.scheduleanything.com, Stripe test mode, D1 staging DB |
| Run full test suite against all 30 toolkits | 1 week | Test report with coverage gaps, file GitHub issues for failures |
| Set up CI/CD pipeline | 0.5 weeks | Lint → Test → Build → Deploy staging on push |
| Finalize tenant router design + DB schema | 0.5 weeks | Worker spec, D1 schema for tenants/usage tables |

### Phase 1: Core Infrastructure (Weeks 3-6)

| Task | Effort | Deliverable |
|---|---|---|
| **Tenant Router** (Cloudflare Worker) — subdomain routing, KV tenant→origin mapping, rate limiting | 1 week | Deployed at `*.scheduleanything.com` |
| **Stripe subscription billing** — extend Cloud Worker: Stripe Products + Prices per tier, `invoice.paid`/`customer.subscription.deleted` webhooks, D1 tenants table | 1.5 weeks | Billing API + webhook handlers |
| **Tenant signup flow** — React registration page, Stripe Checkout, post-payment provisioning trigger | 1 week | Signup → pay → provisioned pipeline |
| **Tenant provisioning** — extend existing async provisioning pattern, Multisite `wpmu_create_blog()`, plugin activation, toolkit seeding, preset installation | 1 week | Automated workspace creation |
| **React SPA scaffold** — Vite + TypeScript + React Router, fork Chat SPA auth layer, Velzon design system from Cloudways Dashboard | 1.5 weeks | Working SPA with tenant-aware REST calls |

### Phase 2: Core Scheduling Product (Weeks 7-10)

| Task | Effort | Deliverable |
|---|---|---|
| **Unified Schedule Builder** — React Flow DAG editor, drag-and-drop tool nodes by toolkit, edge connections, property panel, cron trigger config | 3 weeks | Visual workflow builder |
| **Preset library UI** — browse/search by toolkit/category, one-click install, preview | 1 week | Preset catalog |
| **Schedule run history** — table with status/duration/timestamp, expandable result envelope, filters | 1 week | Run history dashboard |
| **Tenant admin dashboard** — toolkit toggles, AI provider config, user management, overview stats | 1.5 weeks | Admin dashboard |
| **Schedule REST API** — CRUD endpoints, tenant-scoped, dry-run/preview, manual trigger | 1 week | Extended REST API |

### Phase 3: Tenant Experience (Weeks 11-14)

| Task | Effort | Deliverable |
|---|---|---|
| **Customer-facing booking portal** — service → staff → slot → confirm → Stripe payment → confirmation. Public REST endpoints. | 3 weeks | Public booking portal |
| **AI-powered schedule builder** — chat: "Send me a weekly sales report every Monday" → AI creates the workflow via assistant_run | 2 weeks | NL → workflow converter |
| **Per-tenant email templates** — mjml-based, customizable, for confirmations/reminders/reports | 1 week | Email template system |
| **Tenant analytics** — schedules run, tools executed, AI tokens, storage, billing summary | 1 week | Analytics dashboard |

### Phase 4: Production Hardening (Weeks 15-16)

| Task | Effort | Deliverable |
|---|---|---|
| Redis object cache + WP 6.9 query cache | 0.5 weeks | Caching layer active |
| Load testing — 1,000 concurrent tenant simulation | 1 week | Load test report + fixes |
| Security audit — multi-tenant isolation, REST auth, XSS/CSRF/SQLi, Stripe webhook audit | 1 week | Security report |
| Documentation — tenant onboarding, toolkit reference, API docs, billing FAQ | 0.5 weeks | Documentation portal |
| Production deploy — production Cloudways, Cloud Worker, Stripe live, DNS cutover | 0.5 weeks | Live at scheduleanything.com |

**Total MVP: 16 weeks** with a team of 2-3 engineers.

---

## 10. Cost Model & Unit Economics — Platform Owner View

### 10.1 Platform Operating Costs (Monthly)

| Cost Item | 10 Tenants | 100 Tenants | 1,000 Tenants | Notes |
|---|---|---|---|---|
| **Cloudways Hosting** | $50 (1 server) | $200 (2-4 servers) | $1,000-2,000 (10-20 servers) | DO 2GB Premium at $24/mo. 50-100 tenants/server on Multisite. |
| **NV oOS Pro License** | **$0** | **$0** | **$0** | We own it. Zero marginal cost. |
| **NV oOS Base** | $0 | $0 | $0 | GPLv3, our own code. |
| **Cloudflare Workers** | $0 (free tier) | $5-20 | $50-200 | Tenant router + billing worker. 10M req/mo free. |
| **Cloudflare D1** | $0 | $0-5 | $25-100 | Tenant ledger + usage tables. |
| **Cloudflare KV** | $0 | $0-5 | $10-50 | Tenant→origin mappings. |
| **Frontend CDN** | $0 (CF Pages) | $0 | $20 | Static SPA hosting. |
| **Stripe Fees** | 2.9% + $0.30/txn | Same | Same | Per subscription payment. |
| **Domain + DNS** | $20/year | Same | Same | scheduleanything.com |
| **Monitoring** | $0-30 | $30-100 | $100-500 | Sentry + UptimeRobot. |
| **OpenRouter** (NV oOS Cloud) | $0 (BYOK default) | Variable | Variable | Only when tenants opt in. 7% markup covers our costs + profit. |

**Total monthly platform cost:** ~$70-100 at launch. ~$250-400 at 100 tenants. ~$1,500-3,000 at 1,000 tenants. **No licensing line item.**

### 10.2 Per-Tenant Unit Economics

| Tier | Price/Month | Hosting Cost | Stripe Fees | Gross Profit | Margin |
|---|---|---|---|---|---|
| **Starter** | $49 | $2 | $1.72 | $45.28 | **92%** |
| **Professional** | $149 | $2 | $4.62 | $142.38 | **96%** |
| **Enterprise** | $499 | $10 (dedicated) | $14.77 | $474.23 | **95%** |

*Compare to the earlier estimate with third-party licensing ($15-50/tenant): margins were 62-85%. Without licensing costs, margins jump to 92-96%.*

### 10.3 Revenue Projections (Conservative)

| Metric | Year 1 | Year 2 | Year 3 |
|---|---|---|---|
| Total Tenants | 50 | 300 | 1,200 |
| Avg MRR/Tenant | $79 | $89 | $99 |
| MRR | $3,950 | $26,700 | $118,800 |
| ARR | $47,400 | $320,400 | $1,425,600 |
| Gross Profit (92-96%) | $43,600-45,500 | $294,800-307,600 | $1,311,600-1,368,600 |

### 10.4 Break-Even

At $49/mo Starter with 92% margin ($45.28/tenant) and ~$100/mo fixed costs:
- **Break-even: 3 tenants**
- At 50 tenants: ~$2,264/mo gross profit
- At 1,000 tenants: ~$45,280/mo gross profit

---

## 11. Scaling Strategy

### 11.1 Scaling Dimensions

| Dimension | 0-500 Tenants | 500-2,000 Tenants | 2,000-10,000 Tenants | 10,000+ Tenants |
|---|---|---|---|---|
| **WP Instances** | 1-2 Multisite servers | 5-10 Multisite servers | 20-50 servers + dedicated Enterprise | Hybrid: WP admin + external execution engine |
| **Tenant Router** | 1 CF Worker | 1 CF Worker (paid tier) | Regional Workers (US, EU, APAC) | Global anycast |
| **AI Inference** | BYOK or single NV Cloud | Per-tenant cost tracking | Dedicated OpenRouter org per region | Self-hosted Ollama on GPU nodes |
| **Database** | Single MySQL | Read replicas | Replicas + custom booking tables | Sharded by tenant_id |
| **Job Queue** | WP-Cron | Action Scheduler | Cloudflare Queues | Dedicated message bus |
| **Monitoring** | WP debug + Sentry | Datadog/ELK | OpenTelemetry tracing | Full observability |

### 11.2 Critical Milestones

- **At 500 tenants:** Action Scheduler for schedule execution. Database read replicas.
- **At 2,000 tenants:** Custom `wp_mcp_appointments` table for availability queries. Regional tenant routers.
- **At 5,000 tenants:** External job queue. WordPress is configuration/admin only.
- **At 10,000+:** Full microservice extraction. Schedule execution, availability lookups, AI inference run on dedicated services.

---

## 12. Technical Implementation Details

### 12.1 React SPA Structure

```
schedule-anything-spa/
├── src/
│   ├── api/
│   │   ├── client.ts           # @wordpress/api-fetch + nonce auth
│   │   ├── schedules.ts        # Schedule CRUD
│   │   ├── presets.ts          # Preset library API
│   │   ├── toolkits.ts         # Toolkit metadata
│   │   ├── bookings.ts         # Public booking endpoints
│   │   └── billing.ts          # Stripe subscriptions
│   ├── components/
│   │   ├── layout/             # App shell, sidebar, topbar
│   │   ├── builder/            # Visual DAG editor
│   │   │   ├── Canvas.tsx      # React Flow canvas
│   │   │   ├── ToolNode.tsx    # Custom node: tool
│   │   │   ├── TriggerNode.tsx # Custom node: trigger
│   │   │   ├── PropertyPanel.tsx # Right sidebar
│   │   │   └── ToolPalette.tsx # Left sidebar: tools by toolkit
│   │   ├── presets/            # Preset browser + cards
│   │   ├── bookings/           # Public booking components
│   │   ├── analytics/          # Charts + usage stats
│   │   └── shared/             # Skeleton, ErrorBoundary, EmptyState
│   ├── hooks/
│   │   ├── useTenant.ts        # Tenant context
│   │   ├── useSchedules.ts     # react-query CRUD
│   │   ├── usePresets.ts       # Preset queries
│   │   └── useBooking.ts       # Booking flow state machine
│   ├── pages/
│   │   ├── DashboardPage.tsx   # Tenant overview
│   │   ├── SchedulesPage.tsx   # Schedule list + create
│   │   ├── BuilderPage.tsx     # Visual workflow builder
│   │   ├── PresetsPage.tsx     # Preset library
│   │   ├── HistoryPage.tsx     # Run history
│   │   ├── BookingPage.tsx     # Public booking portal
│   │   ├── AnalyticsPage.tsx   # Usage analytics
│   │   ├── SettingsPage.tsx    # Toolkits + AI providers
│   │   └── BillingPage.tsx     # Subscription + invoices
│   ├── contexts/
│   │   ├── AuthContext.tsx     # WP nonce + user
│   │   └── TenantContext.tsx   # Tenant config
│   ├── App.tsx                 # Router + ErrorBoundary
│   └── index.tsx               # DOM mount
├── package.json
├── tsconfig.json
├── vite.config.ts
└── tailwind.config.ts
```

### 12.2 REST API Extensions

| Namespace | Endpoint | Method | Purpose | Auth |
|---|---|---|---|---|
| `mcp-ai-pro/v1` | `/schedules` | GET, POST | List/create schedules | Tenant admin |
| `mcp-ai-pro/v1` | `/schedules/{id}` | GET, PUT, DELETE | Single schedule CRUD | Tenant admin |
| `mcp-ai-pro/v1` | `/schedules/{id}/history` | GET | Run history | Tenant admin |
| `mcp-ai-pro/v1` | `/schedules/{id}/trigger` | POST | Manual trigger | Tenant admin |
| `mcp-ai-pro/v1` | `/presets` | GET | List available presets | Tenant admin |
| `mcp-ai-pro/v1` | `/presets/{id}/install` | POST | Install preset as schedule | Tenant admin |
| `mcp-ai-pro/v1` | `/toolkits` | GET | List toolkits with status | Tenant admin |
| `mcp-ai-pro/v1` | `/toolkits/{slug}/toggle` | POST | Enable/disable toolkit | Tenant admin |
| `mcp-ai-pro/v1` | `/analytics/usage` | GET | Usage stats | Tenant admin |
| `mcp-ai-pro/v1` | `/bookings/slots` | GET | Public availability | **Public** |
| `mcp-ai-pro/v1` | `/bookings` | POST | Create public booking | **Public** (rate-limited) |
| `nvoos-saas/v1` | `/tenants` | GET, POST | Platform tenant CRUD | Platform admin |
| `nvoos-saas/v1` | `/tenants/{id}/provision` | POST | Trigger provisioning | Platform admin |

### 12.3 Cloud Worker Extensions

```sql
-- New D1 tables for tenant billing (extend existing cloud-worker schema)
CREATE TABLE tenants (
  id TEXT PRIMARY KEY,
  slug TEXT UNIQUE NOT NULL,
  tier TEXT NOT NULL CHECK (tier IN ('starter', 'professional', 'enterprise')),
  stripe_customer_id TEXT NOT NULL,
  stripe_subscription_id TEXT,
  wp_origin_url TEXT NOT NULL,
  wp_blog_id INTEGER,
  status TEXT NOT NULL DEFAULT 'provisioning'
    CHECK (status IN ('provisioning', 'active', 'suspended', 'cancelled')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE tenant_usage (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id TEXT NOT NULL REFERENCES tenants(id),
  date TEXT NOT NULL,
  schedules_run INTEGER DEFAULT 0,
  tools_executed INTEGER DEFAULT 0,
  ai_tokens_in INTEGER DEFAULT 0,
  ai_tokens_out INTEGER DEFAULT 0,
  ai_cost_usd REAL DEFAULT 0.0,
  storage_bytes INTEGER DEFAULT 0,
  UNIQUE(tenant_id, date)
);
```

### 12.4 New WordPress Plugin: `schedule-anything-platform`

```
addons/schedule-anything-platform/
├── schedule-anything-platform.php       # Plugin entry
├── includes/
│   ├── class-sa-tenant-controller.php           # Tenant REST endpoints
│   ├── class-sa-multisite-provisioner.php       # wpmu_create_blog() + seeding
│   ├── class-sa-toolkit-manager.php             # Per-blog toolkit feature flags
│   ├── class-sa-usage-tracker.php               # Heartbeat → Cloud Worker
│   └── class-sa-preset-extensions.php           # Additional presets
├── rest/
│   └── class-sa-rest-controller.php             # Custom REST routes
└── tests/
    └── test-tenant-provisioning.php
```

---

## 13. Go-to-Market Strategy

### 13.1 Target Segments

| Segment | Pain Point | Schedule Anything Solution |
|---|---|---|
| **Service Businesses** (consultants, agencies, clinics) | Juggling 5+ tools for booking, CRM, reports | One platform: booking + CRM + reports + reminders |
| **E-commerce Operators** | Manual inventory alerts, cart recovery, sales reports | Automated workflows: inventory→alert, cart→recovery, daily sales digest |
| **Marketing Agencies** | Multi-client social scheduling, engagement reporting | Multi-platform scheduler, AI reports, client dashboards |
| **Professional Services** (law, finance, architecture) | Compliance deadlines, client deliverables, document generation | Domain toolkits + scheduling = automated compliance |
| **Healthcare Providers** | Patient scheduling, reminders, insurance, HIPAA | Dedicated HIPAA instances, integrated booking + records |

### 13.2 Launch Sequence

1. **Closed beta (Month 1):** 10-20 hand-picked service businesses. Free for 3 months for feedback.
2. **Public launch (Month 4):** Product Hunt, WordPress community, indie hacker communities.
3. **Content marketing:** "How to automate your [industry] workflows with AI" — per-industry playbooks from the 50+ presets.
4. **Partnership channels:** Cloudways marketplace, WordPress.org plugin directory (Base plugin listing drives awareness), agency partnerships.

### 13.3 Competitive Moat

| Competitor | What They Do | Our Advantage |
|---|---|---|
| **Calendly / Acuity** | Booking only | 30 toolkits. AI-native. Workflow automation. |
| **Zapier / Make / n8n** | Generic workflow automation | 539+ pre-built AI tools. Domain-specific toolkits. 13 AI providers. |
| **HubSpot** | CRM + marketing | Multi-domain (not just CRM). AI-native. Scheduling as first-class primitive. |
| **Monday.com / Asana** | Project management | AI agents that execute work, not just track it. Law/CRE/Healthcare toolkits. |
| **Custom development** | Bespoke automation | 80% already built. 16-week MVP vs. 12+ month custom build. |

---

## 14. Appendices

### Appendix A: Pre-Built Schedule Presets (Partial Inventory)

| Preset ID | Name | Toolkit | Schedule | Type |
|---|---|---|---|---|
| `inventory_check` | Inventory Level Check | E-commerce | hourly | task |
| `daily_sales_report` | Daily Sales Report | E-commerce | daily | assistant_run |
| `abandoned_cart_followup` | Abandoned Cart Follow-up | E-commerce | every_30m | workflow |
| `price_monitoring` | Product Price Monitor | E-commerce | every_15m | task |
| `order_status_broadcast` | Order Status Broadcast | E-commerce | daily | channel_broadcast |
| `daily_content_scheduler` | Daily Content Scheduler | Social Media | daily | workflow |
| `engagement_report` | Daily Engagement Report | Social Media | daily | assistant_run |
| `trending_topics_monitor` | Trending Topics Monitor | Social Media | every_30m | task |
| `social_analytics_digest` | Weekly Social Analytics Digest | Social Media | weekly | assistant_run |
| `cross_platform_post` | Cross-Platform Post Syndicator | Social Media | daily | workflow |
| `daily_traffic_report` | Daily Traffic Report | Analytics | daily | assistant_run |
| `weekly_performance_digest` | Weekly Performance Digest | Analytics | weekly | assistant_run |
| `realtime_alert_monitor` | Real-Time Traffic Alert | Analytics | every_5m | task |
| `conversion_funnel_report` | Conversion Funnel Report | Analytics | weekly | assistant_run |
| `seo_ranking_check` | SEO Ranking Check | Analytics | daily | task |

*(50+ presets total across 21 toolkits — full inventory in `WP_MCP_AI_Pro_Schedule_Presets`)*

### Appendix B: Toolkit Feature Flags

| Setting Key | Toolkit | Default (MVP) |
|---|---|---|
| `enable_calendar_booking_toolkit` | Calendar Booking | ✅ On |
| `enable_crm_toolkit` | CRM & Email Marketing | ✅ On |
| `enable_ecommerce_toolkit` | E-commerce | ✅ On |
| `enable_social_media_toolkit` | Social Media | ✅ On |
| `enable_analytics_toolkit` | Analytics | ✅ On |
| `enable_project_management` | Project Management | ✅ On |
| `enable_document_generation_toolkit` | Document Generation | ✅ On |
| `enable_multilingual_toolkit` | Multilingual Content | ⬜ Month 2 |
| `enable_media_toolkit` | Media (sharp) | ✅ On |
| `enable_image_production_toolkit` | Image Production | ⬜ Month 3 |
| `enable_video_production_toolkit` | Video Production | ⬜ Month 3 |
| `enable_financial_planner_toolkit` | Financial Planner | ⬜ Month 2 |
| `enable_dj_management_toolkit` | DJ Management | ⬜ Month 4 |
| `enable_health_wellness_management` | Health & Wellness | ⬜ Month 4 |
| `enable_healthcare_imaging` | Healthcare Imaging | ⬜ Month 4 |
| `enable_regulatory_registration_toolkit` | Regulatory Registration | ⬜ Month 4 |
| `enable_law_firm_toolkit` | Law Firm | ⬜ Month 4+ (compliance review) |
| `enable_cre_debt_toolkit` | CRE Debt | ⬜ Month 4+ (compliance review) |
| `enable_places_management` | Places | ⬜ Month 3 |
| `enable_quiz_system` | Quiz System | ⬜ Month 3 |
| `enable_eca_management` | ECA Management | ⬜ Month 3 |
| `enable_site_creator_toolkit` | Site Creator | ⬜ Month 3 |
| `enable_architect_agent_toolkit` | Architect Agent | ⬜ Month 2 |
| `enable_architectural_design_toolkit` | Architectural Design | ⬜ Month 4 |
| `enable_ai_tool_builder_toolkit` | AI Tool Builder | ⬜ Month 4 |
| `enable_chat_channels_toolkit` | Chat Channels | ⬜ Month 2 |
| `enable_extended_cognition_toolkit` | Extended Cognition | ⬜ Month 4 |

### Appendix C: Decision Log

| Decision | Options | Chosen | Rationale |
|---|---|---|---|
| Multi-tenancy | Multisite vs. per-instance vs. custom DB | Multisite (primary) + dedicated (Enterprise) | `wpmu_create_blog()` is instant. Zero code changes for data isolation. Dedicated instances for compliance tenants. |
| Tenant routing | CF Worker vs. nginx vs. WP domain mapping | Cloudflare Worker | Already proven in cloud-worker. Free tier. Global edge. |
| Frontend | React + Vite vs. Next.js | React + Vite SPA | Matches existing Chat SPA + Toolkit Shell patterns. Static hosting. |
| Workflow builder | React Flow vs. xyflow vs. custom | React Flow | MIT license, mature, extensive customization. |
| Job queue (scaling) | WP-Cron → Action Scheduler → CF Queues | Graduated migration | WP-Cron for MVP. AS for 500+. CF Queues for 2,000+. |
| AI inference | BYOK vs. NV oOS Cloud | BYOK default, NV Cloud opt-in | Zero cost exposure. NV Cloud = additional revenue stream. |

### Appendix D: Key Repository References

| Component | Path |
|---|---|
| Schedule Manager | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php` |
| Schedule Presets | `addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php` |
| Calendar Booking CPTs | `addons/pro/includes/calendar-booking/` |
| Calendar Booking Tools (15) | `addons/pro/includes/tools/calendar-booking/` |
| Pro REST Controllers | `addons/pro/includes/rest/` |
| Cloud Worker | `addons/cloud-worker/` |
| SaaS Controller | `addons/saas-controller/` |
| Cloudways Dashboard | `addons/cloudways-dashboard/` |
| Chat SPA (React pattern) | `addons/chat-spa/` |
| Toolkit Shell (React pattern) | `addons/toolkit-shell/` |
| Cloudways API Client | `addons/pro/includes/cloudways/` |
| NV Cloud Service | `addons/pro/includes/services/class-wp-mcp-ai-nv-cloud-service.php` |
| Pro Feature Flags | `addons/pro/README.md` (Toolkit Activation Settings) |
| NV Cloud Feature Doc | `docs/features/nv-cloud.md` |

---

*This proposal is a living document. Update as toolkit audits complete, architecture decisions are made, and tenant feedback arrives during beta.*
