# Proposal 084 — Admin Surface → Complete Console Migration: Gap Analysis

- **Date:** 2026-10-20
- **Status:** Draft — gap analysis (research + inventory complete; migration strategy proposed; approval pending)
- **Surfaces:** `examples/nvoos-complete-velocity/` (target), `includes/admin/` + `addons/pro/includes/admin/` (sources), `addons/pro/assets/spa-v2/` (shared surface), `includes/rest/` + `addons/pro/includes/` (API bridge)
- **Tracks:** Complete Console product surface, WP-admin → SPA migration, REST-first API hardening
- **Follows:** Proposal 083 (Complete Console app — shipped), the standalone Pro SPA (pattern D), Proposal 064 (standalone SPA WP login), the base+pro → Content Graph port track (parity-gate precedent)
- **Companion:** `docs/project/plans/nvoos-complete-console-app-implementation.md`

## 1. Problem Statement

The Complete Console (`examples/nvoos-complete-velocity/`) delivers spa-v2's ten
routes (chat, settings, tools, assistants, workflows, analytics,
knowledge-graph, docs, media, documents) plus a host-owned dashboard and chat
dock. That is the AI-console core — but the base+pro plugin still registers
**~155 menu-registering admin page classes** (plus widgets, columns, and
Site Health panels) across two WP-admin menu hubs and ~30 top-level menus
that are invisible to the Console:

- **Base** (`includes/admin/`): 127 PHP files, two menu hubs
  (`wp-mcp-ai-dashboard` with 23 submenus, `nvoos-pro-dashboard` with 21),
  the settings dashboard (7 tabs), legacy settings, diagnostics, managers,
  approvals, dashboards.
- **Pro** (`addons/pro/includes/admin/`): 159 PHP files — NV CRM, NV Media,
  NV Projects, F&B Management, Chat Channels, E-Commerce/EZuite/FlowHub/
  Shopify/Site-Creator toolkits, Password Vault, Medical Vitals, Registration,
  ~35 "Research & Add" pages, ~6 "Consolidate & Add" pages, ~20 toolkit
  settings pages.

A Complete customer who adopts the Console must still bounce back into
`/wp-admin` (through the proxy) for most administration. The goal of this
analysis is a **complete inventory, an evidence-based difficulty model, and a
phased migration strategy** — not a big-bang rewrite. Some surfaces port
directly (REST already exists); some need an API bridge first; some belong in
wp-admin permanently.

**Goals:**

- **G1** — Enumerate every admin surface in base+pro and classify it into a
  reproducible difficulty tier.
- **G2** — Ground the classification and strategy in industry standards
  (strangler-fig migration, WordPress core's own admin-modernization
  precedent, REST-over-admin-ajax, server-enforced capability discipline).
- **G3** — Produce a phase plan with parity gates and kill switches so each
  migrated surface ships incrementally with zero forced cutover.
- **G4** — State clearly what stays in wp-admin and why (third-party
  surfaces, WP-native screens, site-level plumbing).

**Non-goals:** cross-app SSO (deferred in 083 §6.4), the media-worker
front-end, retiring the standalone connection-screen app, migrating the
front-end chat widget/shortcodes/blocks/Elementor widgets (that is the
Content Graph ecosystem port's UI matrix, a separate track), and any changes
to wp-admin pages that remain unmigrated.

## 2. Research & Industry Standards

### 2.1 Incremental migration — the strangler fig is the standard

- Fowler's original pattern: wrap the legacy system, build new functionality
  in the new system, and "gradually replace" the old until it can be deleted
  ([StranglerFigApplication](https://martinfowler.com/bliki/StranglerFigApplication.html)).
  Modern practical guides emphasize the same three mechanics: a facade
  (here: the serve.mjs BFF proxy already routes `/wp-admin` through the
  Console), **thin vertical slices** (one page per PR), and eventual
  decommissioning once the new path has parity
  ([Catio](https://www.catio.tech/blog/strangler-fig-pattern),
  [OneUptime](https://oneuptime.com/blog/post/2026-01-30-strangler-fig-pattern/view)).
- Applied to us: the Console is the new shell, wp-admin stays live as the
  fallback, and each migrated surface hides its wp-admin page **behind a
  filter** only after a parity gate passes. The URL/route map becomes a
  visible subtraction — progress is measurable per surface
  (see the sequencing discussion in
  [rolling_sloans_hq #317](https://github.com/ia2341/rolling_sloans_hq/issues/317)).
- The repo already proved this discipline in the base+pro → Content Graph
  port: byte-identity verifiers, per-cluster PRs, dual-matrix validation,
  and a tracker. Reuse those mechanics for admin-surface migration.

### 2.2 WordPress core's own precedent — admin is moving to React, one surface at a time

- Core has spent a decade migrating admin surfaces to React (Gutenberg,
  Site Editor, block editor screens) while keeping classic wp-admin PHP pages
  live — the canonical strangler fig at WordPress scale. Core's own design
  team frames it as "the connection between admin and front-end has only
  gotten stronger" and runs an explicit **admin redesign** program
  ([Admin Design Kickoff](https://make.wordpress.org/design/2023/08/10/admin-design-kickoff/),
  [Thinking Through the WordPress Admin Experience](https://make.wordpress.org/design/2022/06/13/thinking-through-the-wordpress-admin-experience/)).
- The WP 7.1 cycle landed **design-system theming**: `wp-theme` stylesheet +
  `ThemeProvider` React package so classic and React admin screens share one
  token system, tracked in Gutenberg issues #71196 (admin redesign) and
  #42388 (primitives); an experiment restyles classic wp-admin controls from
  `--wpds-*` tokens ([Gutenberg PR #82425](https://github.com/WordPress/gutenberg/pull/82425),
  [The Repository](https://www.therepository.email/contributors-propose-design-system-theming-for-wordpress-7-1),
  [The WP Clan](https://thewpclan.com/design-system-theming/)).
- Implication for us: migrating NV oOS admin surfaces into a React console is
  **converging with, not diverging from, the platform's direction**. The
  repo's NDS tokens should track WPDS naming where the concepts overlap so
  future WP-admin embeds (pattern A/C) stay themeable.

### 2.3 REST over admin-ajax for every new slice

- REST endpoints measurably bypass admin-ajax overhead and give structured
  errors, routing, and a real auth model; the ecosystem consensus is that
  modern JS-driven UIs should use REST
  ([Delicious Brains benchmarks](https://deliciousbrains.com/comparing-wordpress-rest-api-performance-admin-ajax-php/),
  [Roots](https://roots.io/wordpress-rest-api-vs-admin-ajax-php-the-modern-choice/),
  [Devnet](https://devnet.hr/blog/ajax-in-wordpress-rest-api-vs-admin-ajax-php/)).
- Base already has 35 REST controllers (173 call sites); Pro has ~4
  `mcp-ai-pro/v1` controllers and **214 admin-ajax call sites** — the Pro
  admin is an admin-ajax estate. Rule: every migrated surface gets a REST
  controller that reuses the existing service layer; admin-ajax handlers
  stay only while a WP-admin page still consumes them, and are deprecated
  when its last consumer is retired.

### 2.4 Capability discipline — server-enforced, capability-driven nav

- Cookie auth for same-origin SPA + `X-WP-Nonce` header is the documented
  WP REST model
  ([REST API Handbook](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/));
  every custom route needs a real `permission_callback` — `__return_true`
  only for genuinely public data
  ([eseospace](https://eseospace.com/blog/wordpress-rest-api-guide/)).
- Hiding UI is a UX affordance, **not** enforcement: the API must refuse
  independently (Google Cloud SPA guidance,
  [Oso RBAC](https://www.osohq.com/learn/rbac-role-based-access-control),
  [SoftwareEngineering SE](https://softwareengineering.stackexchange.com/questions/400129/how-to-implement-authorization-restrictions-with-spa-pages)).
- Nav should be driven by **capabilities from `wp/v2/users/me?context=edit`**,
  never by role-name string matching (role names drift; capabilities are the
  stable contract — see the BarakoBrew case study).
- The Console already mounts real user capabilities (standalone-app recipe);
  the gap is that its sidebar is a static 11-item list. It must become
  capability-filtered as migrated surfaces land.

### 2.5 What stays in wp-admin — keep the platform-admin in the platform

- Production headless-WP migrations keep wp-admin as the data layer and
  platform-admin shell, moving *product workflows* into the app, not
  replicating the platform itself
  ([jwk19 migration notes](https://dev.to/jwk19/from-native-wordpress-to-headless-the-real-engineering-decisions-behind-a-production-migration-2b1j),
  [iwh.gr](https://iwh.gr/en/blog/wordpress-headless-architecture/)).
- Third-party plugin screens (WooCommerce, Elementor, JetEngine), WP-native
  screens (CPT list tables, media library, users, network admin), Site
  Health, and onboarding wizards remain wp-admin surfaces — proxying into
  them is the industry-standard compromise, and re-implementing them is
  scope creep.
- Session model (BFF, same-origin cookie, `Set-Cookie` Domain rewriting) is
  already resolved and shipped in Proposal 083 §2.3 — not re-litigated here.

## 3. Baseline Inventory

### 3.1 Target — the Complete Console today

| Surface | Owner | Notes |
|---|---|---|
| `/` Dashboard | Console host | `src/console/DashboardPage.tsx` — stats + quick actions |
| `#/chat` … `#/documents` | spa-v2 | 10 lazy routes via `HashRouter` (router.tsx) |
| Chat dock | Console host | `src/console/ChatDock.tsx` — persistent dock outside `/chat` |
| Sidebar nav | Console host | **Static 11-item list** (`ConsoleSidebar.tsx`) — no capability filtering |
| Settings | spa-v2 | 9 fields only (3 masked provider keys, model defaults, logging) via new `mcp-ai/v1/settings` controller (#7157) |
| Auth | serve.mjs BFF | Cookie + `wp_rest` nonce; real user + capabilities from `users/me?context=edit` |

### 3.2 Source — base admin surface

- **127 PHP files**: 85 top-level in `includes/admin/` + 42 in
  `sections/`, `widgets/`, `export/`, `measurement/`.
- **Menu tree**: top-level "NV oOS" (settings dashboard) and "NV oOS Pro
  Dashboard" + "Pro Settings" (both registered from the base — the Pro
  dashboard shell lives in `includes/admin/class-wp-mcp-ai-pro-dashboard.php`);
  hubs `wp-mcp-ai-dashboard` (23 submenus) and `nvoos-pro-dashboard` (21);
  `tools.php` entries (4), assistant CPT (8+), team CPT, `upload.php` (3),
  `edit.php?post_type=page` (1).
- **API bridge**: 35 REST controllers in `includes/rest/` (chat, tools,
  assistants, approvals, workflows ×2, triggers, threads, teams,
  security-center, restrictions, cost, status, tokens, voice, transcript
  mining, portability, paper-store, security-training, supplier-security,
  asset-inventory, settings — new) + **214 admin-ajax call sites** in
  `includes/admin/`.
- **Settings system**: modular dashboard (registry + validator + 7 section
  tabs: General, Providers, Authentication, Tools, Integrations, Security,
  Advanced) + legacy 6,318-line settings class still active behind a flag.

### 3.3 Source — Pro admin surface

- **159 files** in `addons/pro/includes/admin/` (+ 4 pages elsewhere:
  paper-store admin UI, outbound-booking dashboard, JetEngine taxonomy
  research-add, the spa-v2 loader).
- **Top-level menus**: Chat Channels, NV CRM, NV Media, NV Projects, F&B
  Management, E-Commerce Toolkit, EZuite Toolkit, FlowHub Toolkit, Shopify
  Sync, Site Creator, Password Vault, Medical Vitals, Medical Imaging
  Viewer, Registration Dashboard, Orchestration Monitor, Health & Wellness,
  CRE Portfolio Dashboard, Skill Manager — plus per-CPT menus.
- **Page families** (shared PHP base classes):
  - *Toolkit settings* (~20 pages) — `WP_MCP_AI_Toolkit_Settings_Base`
    (Overview / Configuration / Tools Management / Research & Add / Help tabs).
  - *Research & Add* (~35 pages) — `WP_MCP_AI_Research_Add_Base`, CPT/CCT
    dual storage, import + create-from-research flows.
  - *Consolidate & Add* (~6 pages) — comic, events, media, products, ECA
    records, health records.
  - *Command centers* — CRM, Media, PM, Pro Agent (blueprints, dashboards).
- **API bridge**: only ~4 `mcp-ai-pro/v1` controllers + `nvoos-media-studio/v1`
  + `jet-engine/v1/mcp`; **214 admin-ajax call sites** carry the rest.

### 3.4 What already exists in the Console (not a gap)

Chat, Assistants, Tools, Workflows, Analytics, Knowledge Graph, Docs, Media
Studio, Document Editor, and the 9-field Settings form — plus the host
dashboard and chat dock. These correspond to spa-v2's feature set and are
**out of scope for this analysis except where they are partial** (see T1/T3).

## 4. Difficulty Model — Six Tiers

Tier assignment is evidence-based: does a REST controller exist (T1), is it
admin-ajax-only (T2), is it a settings/options form that can go
schema-driven (T3), is it a repeating entity-workflow family (T4), is it
wp-admin-native by design (T5), or is it not ours to migrate (T6).

| Tier | Signal | Effort per surface | Pattern |
|---|---|---|---|
| **T0 Covered** | Already a spa-v2 route | — | Done |
| **T1 REST-ready** | `includes/rest/` controller exists | Low | React page + parity tests; reuse controller |
| **T2 AJAX→REST bridge** | admin-ajax only, but a service class exists | Medium | New REST controller on the service layer, then T1 |
| **T3 Schema-driven settings** | options/settings-API page | Medium (first), Low (after) | Extend the settings REST schema; generic form renderer |
| **T4 Entity workflows** | shared PHP base class, ~40 instances | High (first), Low (per instance) | One REST + one configurable React feature; per-entity config |
| **T5 Keep-in-wp-admin** | platform-native or third-party surface | — | Proxy deep-link from Console; documented, not migrated |
| **T6 Out of scope** | WP core / third-party plugin screens | — | Never migrate |

## 5. Gap Matrix — Base Admin

| # | Surface (source class) | Tier | Bridge today | Notes |
|---|---|---|---|---|
| B1 | Settings dashboard 7 tabs + legacy settings | T3 | `mcp-ai/v1/settings/schema` + `/settings/values` (shipped 2.4.0) | ✅ Schema-driven surface ships all standard field types; structural types (`html`/`heading`/`slider`/`custom`/`multiselect`) + Pro toolkit settings stay wp-admin until Phase 2b |
| B2 | Transparency settings, key rotation | T3/T1 | — | Key rotation has security-sensitive UX; treat as T1 once REST exists |
| B3 | Approvals | T1 | `rest-approval-controller` | Pending-count badge parity (menu bubble → sidebar badge) |
| B4 | Slash Commands dashboard | T1 | `rest-slash-command-controller` | Execution + history endpoints exist |
| B5 | Run Timeline | T2 | ajax `run_timeline_*` | Workflow-run REST exists (`rest-workflow-run-controller`) — extend it |
| B6 | Multi-Agent dashboard | T2 | ajax `multi_agent_stats` | Agent re-install is destructive — confirm-gate UX + capability check |
| B7 | Orchestration dashboard | T2 | ajax `orchestration_*` | Overlaps spa-v2 Workflows/Analytics; merge, don't duplicate |
| B8 | DAG Builder | T2 | — | spa-v2 Workflows page is the visual builder already — verify parity, retire the PHP page |
| B9 | Workflow Triggers | T1 | `rest-triggers-controller` | |
| B10 | Cron Manager | T2 | ajax | High-value ops surface; REST + read-only default |
| B11 | DLQ Manager | T2 | ajax | Ops surface; pair with cron manager |
| B12 | Token Manager | T2 | ajax (REST `rest-token-manager` serves **per-user tier/usage**, not API-token CRUD — reclassified 2026-10-20) | Moved to Phase 3 with the other AJAX surfaces |
| B13 | HF Datasets page | T2 | ajax `load_dataset_preview` | |
| B14 | Crawl4AI monitor | T2 | — | Depends on engine state; watch page |
| B15 | Markup telemetry page | T2 | — | |
| B16 | Diagnostics cluster (dashboard, MCP server, provider, REST-context, pro-dashboard diagnostic) | T2 | `rest-health` partially | Merge into one Diagnostics console page |
| B17 | Pro Dashboard hub + chart settings (base-registered) | T2 | `pro-dashboard-rest` | Largely superseded by spa-v2 Analytics — verify, then deprecate hub |
| B18 | Asset inventory | T1 | `asset-inventory-rest` | |
| B19 | Conversation import | T2 | — | File-upload flow; media endpoint + REST |
| B20 | OKF bundle manager | T2 | — | |
| B21 | Measurement dashboard | T1/T2 | `admin-measurement-dashboard` | Ecosystem port already has a copy — reuse its seam |
| B22 | Assistant builder/add/test/portability pages | T1 | `rest-assistant-portability-controller`, assistant CPT | spa-v2 Assistants covers builder; portability (export/import) needs a page |
| B23 | Team/profession research + settings | T4-lite | `rest-teams-controller` | CPT-adjacent; low frequency |
| B24 | Integration screens — JetEngine, WooCommerce, Elementor, plugins | T5 | — | Config of third-party plugins; stays in wp-admin |
| B25 | Media-library columns, Gmail crawl, Auth0 setup, onboarding wizard, Site Health panels, ISO badge, dashboard widgets | T5 | — | WP-native or site-plumbing; proxied |
| B26 | User profile chat-profile/memory pages | T5 | — | Per-user WP profile surface |

## 6. Gap Matrix — Pro Admin

| # | Surface | Tier | Bridge today | Notes |
|---|---|---|---|---|
| P1 | CRM command center + blueprints (companies/contacts/deals/leads/tickets) | T4 | `wp/v2` CPT routes via JetEngine? — verify CCT exposure | CRM skill documents the CCT schema; shared R&A base applies |
| P2 | Media command center + blueprints + design page | T4 | `nvoos-media-studio/v1` partially | Media Studio spa-v2 route already fronts generation; command-center workflows are the gap |
| P3 | PM command center + blueprints (projects/tasks/events/sprints) | T4 | — | |
| P4 | Chat Channels menu (dashboard + subpages) | T2 | — | Overlaps chat transcripts; partial T1 if transcript REST reused |
| P5 | F&B Management — dashboard, settings, ingest & preview | T4 | F&B ingestion REST exists (Proposal 078) | Universalisation track owns the schema; the console port consumes it |
| P6 | Toolkit settings pages (~20: ecommerce, ezuite, flowhub, shopify, site-creator, schedule, regulatory, fnb, nv-cloud, ext-cog, vision-analysis, CPT settings…) | T3 | `mcp-ai-pro/v1` (4 controllers) | Schema-driven settings engine (B1) must serve Pro option groups too — one renderer, many schemas |
| P7 | Research & Add family (~35 pages) | T4 | — | One configurable React feature (entity config: fields, store, import mode, create-from-research) replaces the base class instances |
| P8 | Consolidate & Add family (~6 pages) | T4 | — | Variant of P7 with batch/merge mode |
| P9 | Schedule Manager page | T1/T2 | SA presets/toolkits REST (Proposal 081) | |
| P10 | Workflow Builder page | T0/T2 | spa-v2 Workflows | Verify parity vs the PHP ReactFlow page, then retire |
| P11 | Pro maintenance / incidents / webhook status / status dashboard | T2 | `status-rest-controller` (base) | Ops cluster — group under one Console "Operations" page |
| P12 | Skill manager / skill research / skill settings | T2 | — | Skills are a console-native concept — high priority |
| P13 | Password Vault admin | T5/T2 | metadata-only WP-CLI | Security-sensitive vault; console read-mostly first, admin in wp-admin |
| P14 | Site Creator toolkit settings | T3 | — | |
| P15 | Imaging viewer, vitals dashboards, registration dashboard, CRE portfolio, law-firm dashboard, ECA/health dashboards | T4 | — | Repeating dashboard-with-filters pattern → one configurable dashboard feature |
| P16 | Remote Sites admin (Elementor MCP etc.) | T2 | — | Connection management; high product value for Console |
| P17 | Pro SPA loader / CDN loader / inline assistant | T5 | — | WP embedding infrastructure, not a surface |

## 7. Migration Strategy — Phased Strangler Fig

**Invariants (every phase):**

1. **Server-side enforcement** — every new REST route has a real
   `permission_callback` (`current_user_can()`), `wp_rest` nonce cookie auth,
   never `__return_true` on state-changing routes (repo `wp-rest-api` skill).
2. **REST-first** — no new admin-ajax handlers; migrated surfaces publish a
   controller that wraps the existing service layer (no logic forks).
3. **Secrets stay masked** — settings reads return masked keys (the #7157
   precedent); no secret ever round-trips in plaintext.
4. **Canonical envelope + two-gate sanitisation** for any tool-adjacent code;
   React pages follow the spa-v2/SPA-skill rules (headless Radix components,
   NDS tokens, i18n via `window.wp.i18n`, axe + jsx-a11y gates, vitest).
5. **Two-bundle rule** — every spa-v2 source change rebuilds both
   `addons/pro/assets/spa-v2/assets/dist/` and
   `examples/nvoos-complete-velocity/dist/`.
6. **Kill switch** — every retired wp-admin page is hidden behind a
   `wp_mcp_ai_admin_page_enabled_{slug}` filter, on by default, flipped off
   per-page after parity verification. Nothing is deleted in the migration
   phase.
7. **Capability-driven nav** — the Console sidebar filters items by the
   capabilities in `users/me?context=edit` (map page → required caps in one
   module), never role names.

**Phase 0 — Foundations** — ✅ **SHIPPED 2026-10-20**
(availability + capability-aware nav; see the [implementation plan](../plans/nvoos-complete-console-admin-migration-implementation.md) §2).
Per user direction, the nav hides items whose backing plugin is not
installed/enabled on the target site (Docs ↔ docs-hub, Knowledge Graph ↔
nvoos-content-graph, Media Studio, Document Editor) — driven by the new
`GET /mcp-ai/v1/features` server-side availability map, never URL guessing.
Capability map + sidebar filtering (B-list module shared with the standalone
app); a parity-test harness; a migration tracker in `docs/project/plans/`.

**Phase 1 — T1 ports (REST-ready)** — Approvals, Slash Commands, Tokens,
Triggers, Portability, Security Center, Teams, Threads, Restrictions.
One PR per surface: React page in spa-v2 (so both the plugin bundle and the
Console get it), capability entry, parity tests, wp-admin page filter-gated.

**Phase 2 — Settings engine (T3)** — Generalize `mcp-ai/v1/settings` into a
schema-driven surface: the PHP `WP_MCP_AI_Settings_Registry` (or a new
`settings-schema` registry) describes sections/tabs/fields; one REST
controller serves schemas + values; one React form renders any schema.
Then the base 7 tabs, Pro settings, and the ~20 toolkit settings pages
become **config, not code**. This is the highest-leverage move in the whole
migration (≈30 PHP pages → one feature).

**Phase 3 — Ops cluster (T2)** — Cron, DLQ, diagnostics (one Diagnostics
page), run timeline, multi-agent, orchestration remnants, Pro maintenance/
incidents/webhook/status. Publish REST controllers on the existing service
classes; group into two Console pages ("Operations", "Diagnostics").

**Phase 4 — Entity workflows (T4)** — One configurable "Records" feature
(list/filter/detail/import/create-from-research) driven by per-entity
configs, replacing the R&A and Consolidate families; then the repeating
dashboards (P15) as one configurable dashboard feature; then command
centers (CRM, Media, PM, Chat Channels, F&B) consuming the same feature.
Order by product priority: CRM → Media → PM → F&B → the rest, each behind
its own parity gate.

**Phase 5 — Retirement & hardening** — Flip the kill-switch filters for
verified surfaces (per customer cohort), delete dead admin-ajax handlers
only after their last WP consumer is gone, and record the subtraction in
the tracker.

**Phase 6 — T5 polish** — Deep-link from Console nav into proxied wp-admin
surfaces (integrations, media library, CPT screens) with branded breadcrumbs
and a "back to Console" affordance; document the T5/T6 boundary.

## 8. Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Scope (~155 menu pages + widgets) invites a big-bang attempt | High | Tier system + per-surface PRs; tracker shows subtraction; strangler default keeps wp-admin live |
| Pro admin is admin-ajax estate; REST bridged too slowly | Medium | Phase 2 settings engine and Phase 4 feature configs collapse ~90 PHP pages; controllers wrap services, no logic ports |
| JetEngine CCT storage for R&A entities is not REST-exposed | Medium | One adapter layer per storage backend (CPT ↔ CCT) behind the Records feature; verify exposure per toolkit in Phase 4 |
| Settings written from two surfaces concurrently (Console + wp-admin) | Medium | Both write the same options via one controller; last-write-wins documented; per-page filters reduce dual-surfaces windows |
| Secret exposure through settings schemas | High | Masking contract (never return secrets) enforced in the controller; tests assert masked round-trip |
| Capability drift — console shows links the server refuses | Medium | Capability map module + per-route 403 UX; nav is UX only, server enforces |
| spa-v2 bundle growth as features land | Medium | Route-lazy chunks per feature (existing pattern); per-chunk budget recorded in PR bodies; `spa-bundle-size` gate applies to both bundles |
| Two-bundle sync failure (plugin dist vs console dist) | High | Two-bundle rule; PR body checklists; the #7158/#7159 lesson is in the SPA skill |
| WP 7.1 admin redesign makes wp-admin retention look dated | Low | NDS tokens track WPDS where overlapping; T5 surfaces ride core's own restyle |
| Product confusion — customers still need wp-admin for T5/T6 | Medium | Explicit "platform admin" deep-link section in the Console; T5 boundary documented in-product |

## 9. Open Questions

1. **Retirement policy** — kill-switch default "off after parity" (Console is
   the surface) or "on unless flagged" (wp-admin remains for non-Velocity
   plugin customers)? The Complete bundle is Console-first, but base+pro is
   also sold standalone — likely per-cohort, needs a product decision.
2. **Research & Add shape** — one generic Records feature for all ~41 pages,
   or per-toolkit ports in toolkit waves (CRM first)? Analysis favors the
   generic feature; confirm the first target entity.
3. **Diagnostics merge** — collapse the five diagnostic pages into one
   Console page, or keep the granular WP-admin split? (Analysis: collapse.)
4. **Pro settings schemas** — can every toolkit settings page be expressed by
   the schema engine, or do a few (e.g. F&B ingest, Schedule presets) need
   bespoke React? (Analysis: ~80% schema-able; bespoke list to be produced
   in Phase 2.)
5. **CCT exposure** — which Pro entities are stored in JetEngine CCTs vs CPTs,
   and what's the cleanest read/write path for the Records feature
   (native CPT REST vs a Pro controller)? Needs a per-toolkit data audit in
   Phase 4.
6. **Password Vault** — Console read-mostly with wp-admin write-only, or full
   console management with extra confirmation gates? (Security-sensitive.)

## 10. References

- **Repo:** Proposal 083 (Console app + BFF session model), Proposal 082/081
  (SA REST contract), Proposal 064 (standalone SPA login), Proposal 060 (SPA
  UI stack), Proposal 078 (F&B universalisation), `base-pro-ecosystem-port-gap-analysis.md`
  (parity-gate precedent), `nvoos-complete-console-admin-migration-implementation.md`
  (the operational plan — Phase 0 shipped), `includes/rest/` (35 controllers),
  `addons/pro/includes/admin/` (159 files), spa-v2 `router.tsx`,
  `examples/nvoos-complete-velocity/src/console/`, the `mcp-ai-wpoos-spa-ui`,
  `wp-rest-api`, `wp-security-audit`, `wp-abilities-api` skills.
- **Industry:** Fowler — [Strangler Fig Application](https://martinfowler.com/bliki/StranglerFigApplication.html);
  Catio — [Strangler Fig: Practical Guide](https://www.catio.tech/blog/strangler-fig-pattern);
  OneUptime — [How to Implement the Strangler Fig Pattern](https://oneuptime.com/blog/post/2026-01-30-strangler-fig-pattern/view);
  Make WordPress — [Admin Design Kickoff](https://make.wordpress.org/design/2023/08/10/admin-design-kickoff/);
  The Repository — [Design System Theming for WP 7.1](https://www.therepository.email/contributors-propose-design-system-theming-for-wordpress-7-1);
  Gutenberg — [PR #82425 admin restyle experiment](https://github.com/WordPress/gutenberg/pull/82425);
  Delicious Brains — [REST vs admin-ajax](https://deliciousbrains.com/comparing-wordpress-rest-api-performance-admin-ajax-php/);
  Roots — [REST vs admin-ajax: the modern choice](https://roots.io/wordpress-rest-api-vs-admin-ajax-php-the-modern-choice/);
  WP REST Handbook — [Authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/);
  eseospace — [REST API security guide](https://eseospace.com/blog/wordpress-rest-api-guide/);
  Google Cloud — [Your SPAs Are Vulnerable](https://cloud.google.com/blog/topics/threat-intelligence/single-page-applications-vulnerable/);
  Oso — [RBAC layer](https://www.osohq.com/learn/rbac-role-based-access-control);
  iwh.gr — [Keep the admin, ditch the frontend](https://iwh.gr/en/blog/wordpress-headless-architecture/);
  DEV (jwk19) — [Native WordPress → Headless: production migration decisions](https://dev.to/jwk19/from-native-wordpress-to-headless-the-real-engineering-decisions-behind-a-production-migration-2b1j).
