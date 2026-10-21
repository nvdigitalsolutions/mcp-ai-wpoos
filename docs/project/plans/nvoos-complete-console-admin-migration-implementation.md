# NV oOS Complete Console — Admin Surface Migration: Implementation Plan

- **Date:** 2026-10-20
- **Status:** Phases 0–3 shipped (merged as #7164, 2026-10-21); Phase 3b
  shipped (P9 Schedule Manager port + B8/P10 builder port, PR #7166 —
  workflow-builder retirement soak open into Phase 5); next: Phase 4
  (Records feature — unblocked by the storage audit) and Phase 5 retirement
  soak. Latest validation (per-slice): phpcs 0 errors, PHPUnit green on
  WP 6.9 + WP 7.1 (Docker), spa-v2 vitest 288/288, console vitest 47/47,
  all bundles rebuilt.
- **Companion analysis:** [`docs/project/proposals/084-admin-surface-console-migration-gap-analysis.md`](../proposals/084-admin-surface-console-migration-gap-analysis.md)
- **Precedes:** Proposal 083 implementation plan (console scaffold — complete)
- **Surfaces:** `examples/nvoos-complete-velocity/` (target), `includes/` + `addons/pro/` (source), `addons/pro/assets/spa-v2/` (shared surface), `includes/rest/` (API bridge)

## 1. Objective

Migrate the base+pro WP-admin surfaces into the Complete Console using the
strangler-fig strategy from the gap analysis: per-surface vertical slices,
REST-first bridges, schema-driven settings, per-page kill switches, and a
documented keep-in-wp-admin boundary. This plan operationalizes the six
difficulty tiers (T0–T6) into sequenced work packages with entry/exit
criteria.

**This document is the tracker of record** — Phase tables get a ✅ row when
each surface ships with parity.

## 2. Phase 0 — Foundations: Availability & Capability-Aware Nav (IN PROGRESS)

The console sidebar is currently a static 11-item list and the runtime
hardcodes every endpoint. Two problems follow:

1. **Availability**: menu items (Docs, Knowledge Graph, Media Studio,
   Documents) render even when the backing plugin is not installed/enabled on
   the target WordPress site — dead pages behind dead links.
2. **Capability**: non-admin users see Settings/Workflows/Analytics nav items
   whose REST endpoints the server refuses (the PHP config already gates them
   to `manage_options`).

### 2.1 Design — one server-side source of truth

- **Feature detection lives in PHP, once**: new
  `includes/class-wp-mcp-ai-feature-flags.php` with one static method per
  feature, each returning a boolean and passing through a
  `wp_mcp_ai_feature_available_{key}` filter. The REST endpoint and the
  localized SPA config both consume it — the console never guesses from
  URLs.
- **REST contract**: `GET /mcp-ai/v1/features` (new
  `includes/rest/class-wp-mcp-ai-rest-features-controller.php`) →
  `{ "features": { "<key>": <bool>, ... }, "success": true }`,
  `permission_callback` = `is_user_logged_in()` (feature presence is not
  secret; each surface still enforces its own capability server-side).
- **Runtime plumbing**: `WP_MCP_AI_Pro_SPA_Config::build()` gains a
  `features` map (also consumed by spa-v2's `readProSpaConfig()`); the
  console fetches the map at boot (`fetchFeatureMap()`) and stores it on the
  runtime.
- **Fail-open in the console**: if the features fetch fails (older plugin,
  proxy hiccup), all features default to available — a nav item is never
  hidden because of a transient error, and the feature pages already render
  their own "unavailable" states.
- **Gate model**: each nav item declares `capability?` and `feature?` in one
  config module (`src/console/nav-features.ts`); the sidebar, dashboard
  quick-actions, and route guards all consume `isNavItemVisible()` so the
  three can never disagree.

### 2.2 Feature keys (v1)

| Key | Detection (server-side) |
|---|---|
| `docs_hub` | `class_exists( 'NV_oOS_Docs_Hub_Plugin' )` — covers both the `addons/docs-hub` addon and the standalone `nvoos-docs-hub` wp.org plugin (same class) |
| `knowledge_graph` | `class_exists( 'NvoosContentGraph\Plugin' )` — the standalone `plugins/nvoos-content-graph` plugin |
| `media_studio` | `class_exists( 'NV_oOS_Media_Studio_Shortcode' )` |
| `document_editor` | `class_exists( 'NV_oOS_Document_Editor_Shortcode' )` |
| `pro` | `defined( 'WP_MCP_AI_PRO_FILE' )` |
| `okf` | `class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' )` |

Refactor `WP_MCP_AI_Pro_SPA_Config::build()` so its existing `contentGraph` /
`docsHub` / `mediaStudio` / `documentEditor` / `okf` endpoint gating calls the
same helper — one truth, three consumers (REST, localized config, console).

### 2.3 Nav gate config (v1)

| Route | Capability | Feature |
|---|---|---|
| `/` Dashboard | — | — |
| `/chat` | — | — |
| `/assistants` | — | — |
| `/tools` | — | — |
| `/workflows` | `manage_options` | — |
| `/analytics` | `manage_options` | — |
| `/knowledge-graph` | `manage_options` | `knowledge_graph` |
| `/docs` | `manage_options` | `docs_hub` |
| `/media` | `manage_options` | `media_studio` |
| `/documents` | `manage_options` | `document_editor` |
| `/settings` | `manage_options` | — |

Capabilities come from `runtime.user.capabilities` (already fetched from
`wp/v2/users/me?context=edit`). Route guards (`RequireGate`) redirect blocked
routes to `/` — UI hiding is UX only; server-side `permission_callback`s
remain the enforcement.

### 2.4 Work items

- [x] W0.1 `includes/class-wp-mcp-ai-feature-flags.php` — detection helper + filters.
- [x] W0.2 `includes/rest/class-wp-mcp-ai-rest-features-controller.php` + registration in `includes/bootstrap/loader.php`.
- [x] W0.3 `WP_MCP_AI_Pro_SPA_Config::build()` — consume the helper, add `features` to the runtime.
- [x] W0.4 spa-v2 `api/config.ts` — `features` on `ProSpaRuntime` + parse.
- [x] W0.5 Console `runtime-config.ts` — `features` option + `fetchFeatureMap()`.
- [x] W0.6 Console `main.tsx` — fetch the map during boot.
- [x] W0.7 Console `src/console/nav-features.ts` — nav config + `isNavItemVisible()`.
- [x] W0.8 `ConsoleSidebar.tsx` — filter nav by capability + feature.
- [x] W0.9 `ConsoleShell.tsx` — `RequireGate` route guards on gated routes.
- [x] W0.10 `DashboardPage.tsx` — quick actions filtered by the same config.
- [x] W0.11 Tests — PHPUnit (feature flags + REST controller), vitest (nav
  visibility, route guard, runtime feature parse, fetchFeatureMap fallback).
- [x] W0.12 Rebuild both bundles (two-bundle rule) + lint gates.

**Exit criteria:** console nav hides `#/docs` when docs-hub is absent (and
each other gated item per its gate); non-admin users see the reduced nav;
direct hash-URL visits to gated routes redirect; PHPUnit + vitest green;
both dists rebuilt.

### 2.5 Phase 0-B (deferred, tracked)

- spa-v2 admin layout nav gating (the wp-admin-served SPA consumes the same
  `features` map; its nav component is gated in a follow-up PR).
- Parity-test harness scaffold (`tests/spa-parity/` + a migration tracker
  table — see §6).
- Settings-page deep links: the console Settings route hides for non-admins;
  later phases replace the 9-field form with the schema-driven engine.

**Shipped alongside Phase 0 (2026-10-20):** the spa-v2 Tools page is now
assistant-aware — it reads the shared assistant store (the chat selector's
selection), scopes `GET /mcp-ai/v1/tools?assistant_id=…`, falls back to the
server-resolved default assistant, and shows the scoped assistant in the
subtitle (`api/tools.ts`, `hooks/useTools.ts`, `features/tools/ToolsPage.tsx`).

## 3. Phase 1 — T1 ports (REST-ready surfaces)

One PR per surface. Recipe: React page in spa-v2 (so the plugin bundle and
the console both get it) + nav entry + capability gate + parity tests +
wp-admin page hidden behind `wp_mcp_ai_admin_page_enabled_{slug}`.

| # | Surface | REST | WP page to gate | Status |
|---|---|---|---|---|
| P1.1 | Approvals | `mcp-ai/v1/approvals` | `class-wp-mcp-ai-admin-approvals.php` | ✅ shipped (`#/approvals`, kill-switch added) |
| P1.2 | Slash Commands | `mcp-ai/v1/slash-command` | `class-wp-mcp-ai-admin-slash-commands-dashboard.php` | ✅ shipped (`#/slash-commands`, kill-switch added) |
| P1.4 | Workflow Triggers | `mcp-ai/v1/orchestration/triggers` | `class-wp-mcp-ai-admin-workflow-triggers.php` | ✅ shipped (`#/triggers`, kill-switch added) |
| P1.5 | Assistant Portability | `mcp-ai/v1/assistants/{export,import}` | `class-wp-mcp-ai-admin-assistant-portability.php` | ✅ shipped (`#/portability`, kill-switch added) |
| P1.6 | Security Center | `mcp-ai/v1/security/*` | `class-wp-mcp-ai-security-audit-admin.php` | ✅ shipped (`#/security-center`, kill-switch added) |
| P1.7 | Teams | `mcp-ai/v1/teams` + new `GET /teams` list (2.4.0) | `class-wp-mcp-ai-admin-team-settings.php` | ✅ shipped (`#/teams`, kill-switch added) |
| P1.3 | Token Manager | — (reclassified T2 — see gap analysis B12) | `class-wp-mcp-ai-admin-token-manager.php` | → Phase 3 |

**Phase 1 exit criteria met:** six T1 surfaces shipped with parity-scoped
pages, per-page kill-switch filters (default on), and console + wp-admin SPA
coverage. Next: Phase 2 (settings engine).

**Exit criteria:** each surface visible in the console with parity tests
green; the wp-admin page filter-gated off by default for Console cohorts.

## 4. Phase 2 — Settings engine (T3, highest leverage) — ✅ CORE SHIPPED

- ✅ **Shipped 2026-10-20:** `GET /mcp-ai/v1/settings/schema` (the catalogue
  over every registered settings section), `GET/POST /mcp-ai/v1/settings/values`
  (schema-sanitized partial updates, secrets masked + keep-existing), the
  rewritten spa-v2 SettingsPage (tabbed, schema-driven, dirty-diff saves),
  and the `wp_mcp_ai_admin_page_enabled_settings_dashboard` kill-switch.
  Every standard field type renders in the console: checkbox, number,
  select (enum-checked server-side), text, password, url, email, textarea.
- **Deferred (Phase 2b, tracked):** the structural/admin-only field types
  (`html`, `heading`, `slider`, `custom`, `multiselect`, specialist) stay
  wp-admin-only; Pro toolkit settings pages (not yet registry sections)
  register their fields into the same schema; per-field subtab groups
  (orchestration/integrations views) are flattened for now.
- Migrate in order: base General/Providers/Authentication → Tools →
  Integrations → Security → Advanced → Pro settings → the ~20 toolkit
  settings pages (each becomes a schema registration, not a page).
- **Exit criteria:** every field of the PHP settings dashboard editable in
  the console; masked secrets round-trip; the legacy 6,318-line settings
  class is no longer the console's source of truth.

## 5. Phase 3 — Ops cluster (T2) — ✅ SHIPPED

- ✅ **Shipped 2026-10-20:** `mcp-ai/v1/operations` — `GET /operations/cron` +
  `DELETE /operations/cron/{job_id}` (wrapping `WP_MCP_AI_Cron_Manager`) and
  `GET /operations/dlq` + `POST /operations/dlq/{id}/{retry,dismiss}` (wrapping
  `WP_MCP_AI_Dead_Letter_Queue`); the spa-v2 Operations page (`#/operations`,
  console `/operations`) with cron + DLQ tabs; kill-switch filters
  `wp_mcp_ai_admin_page_enabled_cron_manager` +
  `wp_mcp_ai_admin_page_enabled_dlq_manager`.
- ✅ **Shipped 2026-10-20 (run-timeline slice):** `GET /operations/timeline`
  (args `page`/`assistant_id`) wrapping `WP_MCP_AI_Admin_Run_Timeline::load_run_summaries()`
  — promoted from `private` to `public static` (its post-meta fallback
  promoted alongside; no logic fork); `OperationsClient.timeline()` in the
  spa-v2 client; kill-switch `wp_mcp_ai_admin_page_enabled_run_timeline`;
  PHPUnit `tests/rest-api/test-rest-operations-timeline.php` + vitest
  timeline cases. The Operations page renders the timeline from the shared
  client (no per-page fork).
- ✅ **Shipped 2026-10-21 (diagnostics slice):** `GET /diagnostics/overview`
  (`includes/rest/class-wp-mcp-ai-rest-diagnostics-controller.php`) — one
  read-only payload consolidating the five wp-admin diagnostic pages'
  data: rest-health checks (`WP_MCP_AI_REST_Health::health_check()`),
  system facts, settings-dashboard load state, MCP-server summary,
  provider enablement + credential sources
  (`WP_MCP_AI_Admin_Settings::get_available_providers()` +
  `WP_MCP_AI_Credential_Resolver`), REST context
  (`WP_MCP_AI_REST_API_Context_Fix::get_diagnostics()`), and Pro dashboard
  charts (`WP_MCP_AI_Pro_Dashboard_Diagnostic::run_diagnostics()`) — wraps
  the existing services, no logic forks. Live per-provider/MCP network
  tests stay wp-admin AJAX (tracked follow-up: a POST test route). The
  spa-v2 Diagnostics page renders the consolidated cards in both surfaces
  (spa-v2 `#/diagnostics` route + console `/diagnostics` nav entry, both
  `manage_options`-gated); kill-switch filters
  `wp_mcp_ai_admin_page_enabled_diagnostics_{dashboard,mcp_server,providers,rest_context,pro_dashboard}`
  (default on) gate the five wp-admin pages; PHPUnit
  `tests/rest-api/test-rest-diagnostics-controller.php` + vitest
  `diagnostics-client.test.ts`.
- ✅ **Shipped 2026-10-21 (token-manager slice):**
  `GET /token-manager/overview` +
  `POST /token-manager/credentials/{assistant_id}/{credential_id}/{revoke,delete}`
  + `POST /token-manager/restrictions/{user_id}/lift`
  (`includes/rest/class-wp-mcp-ai-rest-token-manager-credentials-controller.php`)
  — wraps the admin-post revoke/delete handlers' services
  (`WP_MCP_AI_Credentials` — same calls, no logic fork; credential hashes
  never serialized) and the restrictions panel
  (`WP_MCP_AI_Restriction_Registry::get_active()`/`lift()`); service
  `WP_Error`s map to readable 404/400 statuses. The spa-v2 +
  console Token Manager page (`#/token-manager`, `manage_options`-gated)
  renders stats, the credentials table (revoke/delete with confirm), and
  the restrictions panel (lift); kill-switch
  `wp_mcp_ai_admin_page_enabled_token_manager` (default on) gates the
  wp-admin page; PHPUnit `tests/rest-api/test-rest-token-manager-credentials.php`
  + vitest `token-manager-client.test.ts`.
- ✅ **Shipped 2026-10-21 (multi-agent + orchestration stats slice):**
  `GET /orchestration/stats`
  (`includes/rest/class-wp-mcp-ai-rest-orchestration-stats-controller.php`)
  — one read-only overview serving the multi-agent and orchestration
  dashboards' collectors (promoted `protected → public`, no logic forks):
  `WP_MCP_AI_Admin_Multi_Agent_Dashboard::get_agent_statistics()` (agent
  inventory, workflow patterns — raw assistant `config` blocks never
  serialized) and
  `WP_MCP_AI_Admin_Orchestration_Dashboard::get_orchestration_statistics()`
  + `get_system_status()` + `get_recent_workflows()`. The spa-v2 + console
  Orchestration page (`#/orchestration-stats`, `manage_options`-gated)
  renders agent stats, profession/role seeding, subsystem status, and
  recent workflows; kill-switches
  `wp_mcp_ai_admin_page_enabled_multi_agent_dashboard` +
  `wp_mcp_ai_admin_page_enabled_orchestration_dashboard` (default on) gate
  the two wp-admin dashboards; PHPUnit
  `tests/rest-api/test-rest-orchestration-stats.php` + vitest
  `orchestration-stats-client.test.ts`. Destructive/write actions (agent
  re-install, workflow execute/restart, seeders) stay wp-admin — tracked
  follow-up.
- ✅ **Shipped 2026-10-21 (Pro ops slice — gap P11):**
  `GET /mcp-ai-pro/v1/ops/overview`
  (`addons/pro/includes/rest/class-wp-mcp-ai-pro-rest-ops-controller.php`,
  registered from `mcp-ai-wpoos-pro.php`) — one read-only overview grouping
  the four wp-admin Pro ops surfaces: service-status snapshot
  (`WP_MCP_AI_Service_Status_Registry` cache-only read, same as the AJAX
  refresh), maintenance windows (`mcp_ai_maintenance` CPT + meta),
  incidents (`mcp_ai_incident` CPT + phase/severity/services meta), and
  webhook-capable connections
  (`WP_MCP_AI_Pro_Webhook_Status_Page::get_webhook_connections()` +
  `get_expected_webhook_url()` — list only). Live Telegram
  setWebhook/deleteWebhook calls and maintenance/incident creation stay
  wp-admin (network-bound / forms) — tracked follow-up POST routes. The
  spa-v2 + console Pro Ops page (`#/pro-ops`, `manage_options`-gated)
  renders the four sections; kill-switches
  `wp_mcp_ai_admin_page_enabled_pro_{maintenance,incidents,webhook_status,status_dashboard}`
  (default on) gate the four wp-admin pages; PHPUnit
  `tests/rest-api/test-rest-pro-ops.php` (self-skipping on base-only
  matrices) + vitest `pro-ops-client.test.ts`.

**Phase 3 exit criteria met** — all tracked slices shipped (operations,
run-timeline, diagnostics, token manager, multi-agent/orchestration stats,
Pro ops). Next: Phase 3b (P9 + B8/P10), then Phase 4 (Records feature) +
Phase 5 retirement soak.
- New REST controllers wrap the existing service classes; admin-ajax
  handlers deprecated when their last WP consumer is gone.

## 5b. Phase 3b — Remaining T1/T2 surfaces (P9 + B8/P10) — SEQUENCED

Two gap-matrix rows remain in the T0–T2 band after Phase 3. Sequenced here
(2026-10-21) ahead of Phase 4; either work package may proceed
independently.

### 5b.1 P9 — Schedule Manager page (T1/T2)

The Pro Schedule Manager already has a REST bridge — the SA presets/toolkits
REST (Proposal 081) and `WP_MCP_AI_Pro_Schedule_REST_Controller` (registered
in the Pro module registry). The port consumes those instead of the admin
AJAX.

- [x] W9.1 REST audit — the existing bridge is complete: `mcp-ai-pro/v1`
  `/schedules` (list/create), `/schedules/{id}` (get/update/delete),
  `/schedules/{id}/{trigger,toggle,history}`
  (`class-wp-mcp-ai-pro-schedule-rest-controller.php`, redacted public
  shape) + `/presets`, `/presets/{id}/install`
  (`class-wp-mcp-ai-pro-sa-rest-controller.php`, Proposal 081). No new
  controller required — the port consumes these directly.
- [x] W9.2 spa-v2 `ScheduleManagerPage` — schedules tab (create form, run
  now, enable/disable, delete) + presets tab (install) via
  `api/schedule-manager.ts` + `hooks/useScheduleManager.ts`; route
  `#/schedule-manager`.
- [x] W9.3 Console route + nav entry (`/schedule-manager`,
  `manage_options`, registered in `nav-features.tsx` — never inline).
- [x] W9.4 Kill-switch `wp_mcp_ai_admin_page_enabled_pro_schedule_manager`
  on the wp-admin page.
- [x] W9.5 Tests (vitest `schedule-manager-client.test.ts` — the PHP REST
  surface predates this port and is already covered by its own suites) +
  both-bundle rebuild.
- **Exit:** schedules and presets fully operable in the console; the wp-admin
  page filter-gated off by default for Console cohorts.

### 5b.2 B8 + P10 — DAG Builder & Workflow Builder (re-tiered T2/T3)

**W10.1 audit (2026-10-21) — the premise was wrong:** the spa-v2
`WorkflowsPage` is a "Coming Soon" placeholder, not the ReactFlow visual
builder the gap analysis assumed. There is no console builder to verify
parity against — the port is real work:

- Base **DAG Builder** (`includes/admin/class-wp-mcp-ai-admin-dag-builder.php`,
  196 lines) — workflow-CPT sidebar + canvas root driven by bundled JS
  against `mcp-ai/v1/orchestration/workflows`
  (`class-wp-mcp-ai-rest-workflow-cpt-controller.php` — already REST-backed).
- **Pro Workflow Builder**
  (`addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`,
  1,890 lines) — ReactFlow canvas with 12 admin-ajax handlers (save/load/
  delete/list/export/duplicate/rename, templates, node execution,
  execution save) + workflow presets (10 categories) via AJAX
  (`WP_MCP_AI_Pro_Workflow_Presets`), plus its own ReactFlow bundles.

P10 is re-tiered to **T2/T3** (B12 reclassification precedent); W10.2 is
re-scoped as the builder port; kill-switches ship now (zero-risk, default
on); retirement stays gated on the port.

- [x] W10.1 Feature parity audit — recorded above; gap-matrix rows B8/P10
  notes corrected.
- [ ] W10.2 Builder port (sub-steps):
  - [x] W10.2a spa-v2 Workflows page: workflow list + canvas shell consuming
    `mcp-ai/v1/orchestration/workflows` (base REST already exists) —
    placeholder replaced with full CRUD (create/rename via list/run/
    delete) + a canvas shell rendering the selected graph's metadata;
    `api/workflow-builder.ts` + `hooks/useWorkflowBuilder.ts` +
    `workflowBuilder` endpoint in both runtime configs; vitest
    `workflow-builder-client.test.ts`.
  - [x] W10.2b ReactFlow canvas parity — `@xyflow/react` v12 added to the
    spa-v2 bundle; `WorkflowCanvas` (provider-wrapped, lazy-split route)
    with the three Engine V2 node kinds (tool/action/agent — matching
    `execute_*_node` in the Pro builder and Engine V2's default), palette
    add, drag-to-connect with arrow markers, selected-node rename/delete,
    MiniMap/Controls/Background, and save-back through the existing PUT;
    pure `workflow-graph.ts` model (converters + `validateGraph`); vitest
    `workflow-canvas.test.tsx` (converters, validator, node components
    under ReactFlowProvider).
  - [x] W10.2c Workflow presets: 10 categories + install — new REST bridge
    `GET /mcp-ai-pro/v1/workflow-presets` +
    `POST /workflow-presets/{id}/install`
    (`addons/pro/includes/rest/class-wp-mcp-ai-pro-workflow-presets-rest-controller.php`,
    wraps `WP_MCP_AI_Pro_Workflow_Presets`); Presets tab in the console
    Workflows page (category filter, load-into-canvas draft); the canvas
    preserves preset node types outside the execution trio
    (input/output/condition, report kinds) via `data.rawType` round-trip;
    PHPUnit `tests/rest-api/test-rest-workflow-presets.php` + vitest
    `workflow-presets-client.test.ts`.
  - [x] W10.2d Node execution + execution history REST bridge —
    `POST /mcp-ai-pro/v1/workflow-builder/nodes/execute`
    (`addons/pro/includes/rest/class-wp-mcp-ai-pro-workflow-node-rest-controller.php`)
    wraps the builder page's promoted `execute_action_node`/
    `execute_tool_node`/`execute_agent_node` seams and fires the same
    `wp_mcp_ai_pro_workflow_node_executed` action the AJAX path uses, so
    the Pro Workflow Bridge keeps mirroring runs into the base Workflow
    Run CPT (history read via the existing `/orchestration/runs` — no new
    history controller); `workflowNodes` + `workflowRuns` endpoints in both
    runtime configs; canvas "Run node" button (gated on
    `nodeExecutePayload`), run-history section and `lastNodeResult` on the
    Workflows page; preset round-trip fidelity fixed (`toGraphNode` re-nests
    non-core data under `data`, preserves `position`; `toCanvasNode` falls
    back to `data.label`/`data.description`); PHPUnit
    `tests/rest-api/test-rest-workflow-node.php` (5 tests) + vitest client
    and canvas tests; both bundles rebuilt.
- [x] W10.3 Kill-switches `wp_mcp_ai_admin_page_enabled_dag_builder` +
  `wp_mcp_ai_admin_page_enabled_pro_workflow_builder` on the two wp-admin
  pages (default on).
- [x] W10.4 Retirement soak — started 2026-10-21 (W10.2 shipped). Console
  surface is at parity (list + CRUD, ReactFlow canvas, 10 preset categories,
  node execution, run history) with both kill-switches live. The PHP pages
  stay default-on through the soak; removal is a Phase 5 mechanical job:
  (1) flip both kill-switch defaults to off, (2) delete
  `includes/admin/class-wp-mcp-ai-admin-dag-builder.php` + its menu
  registration and the 12 admin-ajax handlers + preset AJAX in
  `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`,
  (3) drop the Pro builder's own ReactFlow bundles from the enqueue path,
  (4) subtract the B8/P10 rows from the gap-analysis tracker. Node-execution
  REST and the presets REST controllers are the surviving consumers of the
  promoted seams/`WP_MCP_AI_Pro_Workflow_Presets` and are NOT removed.
- **Exit:** one workflow-builder surface (console/spa-v2) with full parity;
  both PHP pages retired (landing inside the Phase 5 window).

**Phase 3b exit criteria met** — P9 (Schedule Manager) and B8/P10 (workflow
builder: list + CRUD, ReactFlow canvas, presets, node execution, run
history) are fully operable in the console; kill-switches live on both
wp-admin pages; retirement soak open, removal slated for Phase 5.


## 6. Phase 4 — Entity workflows (T4)

- One configurable **Records** feature (list/filter/detail/import/
  create-from-research) driven by per-entity configs; replaces the ~35
  Research & Add and ~6 Consolidate & Add pages.
- One configurable **dashboard** feature for the repeating dashboards (CRE
  portfolio, ECA, health, vitals, registration, law-firm).
- Command centers (CRM → Media → PM → Chat Channels → F&B) consume both
  features.
- Requires the per-toolkit CCT/CPT storage audit (gap analysis Q5) before the
  first entity — **✅ DONE 2026-10-20:** [`phase-4-storage-audit.md`](./phase-4-storage-audit.md).
  CRM/PM core entities are `show_in_rest=true` CPTs (WP core REST path);
  appointments, F&B and CCT-backed surfaces use toolkit REST or a thin
  `records/{entity}` controller. Q5 resolved; Q2's UX half remains open.

### 6a. Sequenced sub-phases (audit §4 order; B23 sequenced 2026-10-21)

- [x] **W11 (4a) — Records feature + CRM command center.** P1 + P7/P8 pilot:
  the generic list/filter/detail Records page parameterized by entity
  configs (storage strategy = config, not code — `wp-core` first) with the
  five CRM entities (company/customer/deal/lead/ticket via
  `wp/v2/mcp_ai_*`) and the CRM command-center nav entry. Import and
  create-from-research are 4a-b (second slice of the same feature).
  **Slice 1 shipped 2026-10-21 (company pilot):**
  `GET /mcp-ai-pro/v1/records/schema` (entity configs built from
  `WP_MCP_AI_Pro_CPT_Meta_Schema`) + `register_post_meta` REST exposure of
  the company schema keys (the architectural-drawing CPT precedent);
  console `/crm` route + nav entry; `api/records.ts` +
  `features/records/RecordsPage.tsx` (entity tabs, search, schema columns,
  detail panel); `wpBase`/`recordsSchema` endpoints in all three runtime
  configs; PHPUnit `tests/rest-api/test-rest-records-schema.php` (5 tests)
  + vitest `records-client.test.ts` (5 tests). **Slice 2 shipped
  2026-10-21 (full entity set):** meta schemas for customer/deal/lead/
  ticket enumerated from their CPT files and added to the schema registry;
  all five CRM entities in `RECORD_ENTITIES`; deal meta spot-checked in the
  PHPUnit suite (26 assertions). **Slice 3 shipped 2026-10-21
  (create-from-research):** `POST /mcp-ai-pro/v1/records/{entity}/research`
  runs the base `deep_research` tool (basic, immediate, no site content)
  then maps the report onto the entity's schema fields via
  `WP_MCP_AI_Pro_Records_Research_Extractor` (a Pro subclass of the deep
  research tool — provider chains stay in one place; one bounded JSON
  completion with a field contract from the schema); the console Research &
  Add card offers the suggested fields as an editable form and creates the
  reviewed record via `POST wp/v2/{post_type}`. PHPUnit 10 tests / 43
  assertions (zero-network: stub research tool + no-provider gate) + vitest
  research/create contract tests. **W11 exit criteria met** — the five CRM
  entities are operable in the console with list/search/detail and
  research-driven create; import mode deferred to the dashboard-feature
  pass (W13) if still needed.
- [x] **W12 (4b) — PM command center + professions.** P3 (projects/tasks/
  events via `wp/v2/mcp_ai_*`) reusing the W11 Records feature;
  **B23 Team/profession research + settings (T4-lite)** — the console
  `/teams` page (Phase 1) already covers the team catalogue + profession
  members via `rest-teams-controller`; 4b adds profession definitions
  (create/edit `mcp_ai_profession` records, role assignments) and ports the
  profession research/settings wp-admin pages onto the same controller
  surface.
  **Shipped 2026-10-21:** the profession CPT (`includes/professions/`) is
  flipped to `show_in_rest=true` with REST item schemas for its array meta
  (same access as the existing wp-admin editor); a `mcp_ai_profession`
  block joins the meta-schema registry; `project`/`task`/`event`/
  `profession` join `RECORD_ENTITIES` with `group` partitions (crm/pm/
  professions); console routes `/pm` + `/professions` render the Records
  page filtered per group (nav entries added); the records meta registrar
  skips keys the owning CPT already REST-exposed (idempotence guard) and
  emits items schemas for array fields. PHPUnit 10/55 on WP 6.9 + 7.1;
  vitest `filterEntitiesByGroup` coverage. **Remaining for B23 parity:**
  team→profession role-assignment UI on the Teams page (tracked under the
  console Teams surface).
- [ ] **W13 (4c) — Repeating dashboards feature.** P15: one configurable
  dashboard-with-filters feature (CRE portfolio, ECA, health, vitals,
  registration, law-firm). Sequenced 2026-10-21 into sub-slices:
  - [x] **W13a — generic dashboards scaffold + CRE pilot.**
    `GET /mcp-ai-pro/v1/dashboards/schema` (dashboard configs: label,
    filter fields, metric card list) + `GET /dashboards/{id}/data` for the
    CRE portfolio (wraps the `ajax_filter_portfolio` query in
    `class-wp-mcp-ai-cre-debt-dashboard-page.php` via a promoted public
    seam — `get_portfolio_data()` is now public static); console
    `dashboards` feature (`/dashboards` route + nav, `🧮`) with a
    dashboard picker, schema-driven filter form, metric cards and the loan
    summary table; `dashboardsSchema` endpoint in all three runtime
    configs. PHPUnit `tests/rest-api/test-rest-dashboards.php` (6 tests,
    zero-network) + vitest `dashboards-client.test.ts` (6 tests).
  - [x] **W13b — chart cards.** The dashboards page renders the CRE
    distributions (by loan type / status / property type, maturity
    schedule) through the shared `renderChartRecipe` tier via a pure
    `distributionChartSpec()` builder (sorted descending, empty-safe);
    vitest spec coverage.
  - [ ] **W13c — remaining dashboards.** ECA, health, vitals,
    registration, law-firm — one REST data provider each, reusing the
    W13a scaffold; per-dashboard checks against the storage audit §1/§2.
- [ ] **W14 (4d) — Remaining command centers.** Calendar booking (existing
  REST), F&B (Proposal 078 universalisation REST), Media (P2), Chat
  Channels (P4) — per-toolkit checks against the storage audit before each
  port.

## 7. Phase 5 — Retirement & hardening

Kill-switch flips per surface after a soak window; dead admin-ajax handler
removal; tracker subtraction recorded in the gap analysis tables.

## 8. Phase 6 — T5 polish (keep-in-wp-admin)

Branded deep links from the console into proxied wp-admin surfaces
(integrations, media library, CPT screens, Site Health) with breadcrumbs
back; T5/T6 boundary documented in-product and in the gap analysis.

## 9. Cross-cutting gates (every phase)

1. Server-side `permission_callback` on every new route; never
   `__return_true` on state-changing routes.
2. REST-first: no new admin-ajax handlers.
3. Secrets masked in reads (the `rest-settings-controller` precedent).
4. Canonical envelope + two-gate sanitisation for any tool-adjacent code.
5. spa-v2 source changes rebuild **both** `addons/pro/assets/spa-v2/assets/dist/`
   and `examples/nvoos-complete-velocity/dist/`.
6. Headless Radix components, NDS tokens, `window.wp.i18n`, axe + jsx-a11y
   gates, vitest.
7. Capability/feature-driven nav via the Phase 0 config module — new pages
   register there, never inline.

## 10. Open decisions (from gap analysis §9)

Retirement policy per cohort (Q1), Research & Add generic-feature shape (Q2),
diagnostics merge (Q3), settings schema coverage (Q4), CCT exposure audit
(Q5), Password Vault stance (Q6). Each is called out in the phase that
needs it; Phase 4 is blocked on Q5.
