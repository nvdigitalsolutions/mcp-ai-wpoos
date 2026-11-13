# Proposal 090-B — Laravel Admin Surfaces: Implementation Plan

**Status:** Phases 0–5 implemented and verified — the base console, the Phase 3 Pro surfaces, the manifest-driven toolkit engine (both runtimes), its contract fixture harness, the commercial licence source, the read-only profile, the schema/contract version gate and the 30-minute soak all run on the Laravel adapter (2026-11-13) · Phase 5 delivered in PRs #7664–#7668
**Parent proposal:** [`090-b-laravel-admin-surfaces-proposal.md`](../proposals/090-b-laravel-admin-surfaces-proposal.md) (PR #7608)
**Related:** 083 (Complete Console), 084 (Admin-Surface Console Migration), 085 + Laravel appendix
(Funiq), 090 (Laravel adapter spike), 143 (Service Exchange)
**Scope:** Task-level plan for serving the base and Pro admin surfaces from a Laravel backend
without porting ~290 PHP screens.

---

## 1. Scope and success criteria

The console is the admin UI for both runtimes. Laravel implements the REST contract, the console
reuses the same `dist/`, and Pro toolkits are described by manifests rather than bespoke pages.

| Phase | Success criterion |
|---|---|
| 0 — Contract freeze | The console boots against Laravel, asserts the contract, and exposes features/site/session/tools/ops |
| 1 — T0 ops console | 5 T0 pages + chat render against Laravel with zero WordPress requests |
| 2 — T1 assistant studio | Assistant create → export → import round-trip; Service Exchange bundle import works |
| 3 — T2 core operations | Contract suite green for every core family; auth matrix green |
| 4 — T3 Pro families | A pilot toolkit installs from a manifest with zero bespoke PHP pages |
| 5 — GA hardening | Two consecutive releases with no contract drift; commercial entitlement wired |

**Non-goals:** 1:1 parity with all admin files, a second UI, WordPress core/third-party screens,
multisite network admin, WP-native integrations (Elementor, JetEngine, WooCommerce admin, WPCode,
Rank Math).

---

## 2. Current status

| Workstream | Status | Evidence |
|---|---|---|
| `ProEntitlementInterface` contract | **Done** | `lib/core/src/Domain/Contract/ProEntitlementInterface.php` |
| Laravel entitlement adapter + config + binding | **Done** | `lib/laravel-adapter/src/Adapter/ProEntitlement.php`, `config/nvoos.php`, `NvoosServiceProvider` |
| Backend profile + path rewrite | **Done** | `examples/nvoos-complete-velocity/src/backend-profile.ts` |
| Runtime profile in `buildConsoleRuntime` | **Done** | `src/runtime-config.ts` |
| Startup contract assertion | **Done** | `assertBackendContract()` + `src/main.tsx` |
| Proxy backend switch | **Done** | `scripts/serve.mjs` (`NVOOS_BACKEND`) |
| T0 endpoints | **Done** | `/api/v1/{features,site,session/*,tools,pro/ops/overview}` |
| CSRF for stateful console requests | **Done** | Laravel `ValidateCsrfToken` + console sends `X-CSRF-TOKEN` (`src/fetch-wrapper.ts`) |
| Phase 0 tests | **Done** | `Phase0ContractTest`, `AcceptWpNonceTest` |
| Settings (schema/values/update) | **Done** | `SettingsController`, `SettingsSchema` |
| Diagnostics overview | **Done** | `DiagnosticsController` |
| Operations (cron/DLQ/retry/dismiss/timeline) | **Done** | `OperationsController`, `JobStatusStore` |
| Laravel login/logout (C2) | **Done** | `SessionController`, console `login.ts` |
| Assistant CRUD + default handling | **Done (Phase 2)** | `AssistantController`, `Assistant` model |
| Bundle v1 export/import | **Done (Phase 2)** | `AssistantPortabilityController` |
| Assistant domain contract | **Done (Phase 2)** | `LaravelAssistantService` |
| Transcripts + memory + session logs | **Done (Phase 3a)** | `TranscriptStore`, `MemoryStore`, `SessionLogStore`, console endpoints |
| Token usage + cost analytics | **Done (Phase 3a)** | `TokenUsage`, `AnalyticsController` |
| Rate limiter | **Done (Phase 3a)** | `RateLimiter` adapter |
| Golden fixtures captured from WordPress | **Pending** | Shape tests stand in; capture requires a live Complete site |
| Playwright T0 smoke (G1) | **Pending** | Phase 1 task 1.7 |
| Blueprints/teams/test-assistant | **Pending (Phase 2)** | See §6 |
| Approvals queue (list/create/approve/deny) | **Done (Phase 3b)** | `ApprovalsController`, `nvoos_approvals` |
| Workflow triggers (list/create/update/delete) | **Done (Phase 3b)** | `TriggersController`, `nvoos_triggers` — served at `/triggers` and the console's `/orchestration/triggers` |
| Security posture / header preview / snapshots / self-test | **Done (Phase 3b)** | `SecurityController`, `nvoos_security_snapshots` |
| Paper Store (collections, record CRUD, search) | **Done (Phase 3b)** | `PaperStoreController`, `nvoos_paper_records` |
| Schedule Manager (CRUD, toggle, trigger, history, results, fleet stats) | **Done (Phase 3c)** | `Pro\SchedulesController`, `nvoos_schedules` + `nvoos_schedule_runs` |
| Records schema + `wp/v2` content shim | **Done (Phase 3c)** | `Pro\RecordsController`, `Wp\ContentController` over `nvoos_posts` |
| Pro entitlement middleware on Pro routes | **Done (Phase 3c)** | `EnsureProEntitlement` (402 typed error, fails closed) |
| Media library (list, read, multipart upload) | **Done (Phase 3d)** | `MediaController` + `Laravel\Adapter\FileUploadService` over `nvoos_files` |
| Manifest-driven Pro toolkits | **Done (Phase 4.1/4.3)** | `ToolkitManifest`, `ToolkitManifestLoader`, `Pro\ToolkitsController`, `nvoos_toolkit_records` |
| Schedule presets, record remote/research, thumbnails | **Pending (Phases 3–4)** | §7–§8 remainder |
| Generic toolkit console screens | **Done (Phase 4.2)** | `spa-v2/src/features/toolkits/**`, `api/toolkits.ts`, `styles/toolkits.css` |
| Console session/CSRF regression fixes | **Done** | single session store on login (`routes/api.php`), `AcceptWpNonce` header alias, stateful test origin |

**Validation record (2026-11-12):**

- Adapter PHPUnit: **34 tests, 101 assertions**
- Console Vitest: **14 files, 132 tests**
- Console typecheck: clean
- Laravel app PHPUnit: **96 tests, 672 assertions**
- Console smoke (Laravel runtime, 2026-11-12): login → `/pro/toolkits` catalogue → Commerce settings / workbench create / dashboard aggregates all render against the seeded app at `:8001`
- Core PHPUnit: **937 tests, 2,017 assertions** — 14 new `ToolkitManifestTest` tests pass; the 5 errors in `ToolRegistryIntegrationTest` are pre-existing (its discovery loop instantiates the abstract `AbstractResultTool`) and unrelated to this work
- Phase 3b soak (30 min, 2026-11-12): **1,644 iterations / 19,731 requests / 0 violations** — p50 81.9 ms · p95 212.4 ms · p99 338.8 ms · max 982.2 ms at 10.96 req/s; app RSS flat (203 → 194 MiB), DB flat
- `node --check scripts/serve.mjs`: clean

---

## 3. Workstream map

| ID | Workstream | Owner area | Phases |
|---|---|---|---|
| WS-1 | Contract surfaces (`mcp-ai/v1`, `mcp-ai-pro/v1`) | `lib/core`, Laravel app | 0–4 |
| WS-2 | Console runtime + proxy + nav | `examples/nvoos-complete-velocity` | 0, 5 |
| WS-3 | Platform adapters (assistant, transcript, memory, tokens, media) | `lib/laravel-adapter` | 2–3 |
| WS-4 | T0 ops surfaces | Laravel app | 1 |
| WS-5 | Assistant studio + portability | Laravel app + core | 2 |
| WS-6 | Core operations | Laravel app + adapters | 3 |
| WS-7 | Pro manifest engine + toolkit families | Laravel app + console | 4 |
| WS-8 | Entitlement, CSRF migration, observability, GA | Laravel app + console | 5 |

---

## 4. Phase 0 — Contract freeze (complete)

| ID | Task | Files | Status |
|---|---|---|---|
| 0.1 | Define `ProEntitlementInterface` (`allows`, `plan`, `source`, `features`) | `lib/core/src/Domain/Contract/ProEntitlementInterface.php` | ✅ |
| 0.2 | Implement `allow` / `deny` / `config` drivers; unknown fails closed | `lib/laravel-adapter/src/Adapter/ProEntitlement.php` | ✅ |
| 0.3 | Add `oos.pro_entitlement` + `oos.features` config; bind as a singleton | `config/nvoos.php`, `NvoosServiceProvider.php` | ✅ |
| 0.4 | Add `backend-profile.ts`: backend resolution + path matrix | `src/backend-profile.ts` | ✅ |
| 0.5 | Add `backend` to `buildConsoleRuntime`; rewrite `apiUrl`/`proApi`; empty WP-only addon bases | `src/runtime-config.ts` | ✅ |
| 0.6 | Add `assertBackendContract()` and call it during boot | `src/runtime-config.ts`, `src/main.tsx` | ✅ |
| 0.7 | Add `NVOOS_BACKEND` proxy profiles (`wordpress`/`laravel`/`hybrid`) | `scripts/serve.mjs` | ✅ |
| 0.8 | Add T0 endpoints: features, site, session nonce/me, tools, ops overview | `docker/laravel/app-skeleton/app/Http/Controllers/`, `routes/api.php` | ✅ |
| 0.9 | Add the `X-WP-Nonce` → `X-CSRF-TOKEN` middleware (matching token only) | `app/Http/Middleware/AcceptWpNonce.php`, `bootstrap/app.php` | ✅ |
| 0.10 | Enable Sanctum SPA cookie mode (`statefulApi()`) | `bootstrap/app.php` | ✅ |
| 0.11 | Add `JobStatusStore::counts()` for ops diagnostics | `lib/laravel-adapter/src/Adapter/JobStatusStore.php` | ✅ |
| 0.12 | Feature/unit tests for all of the above | `tests/Feature/Phase0ContractTest.php`, `tests/Unit/AcceptWpNonceTest.php`, adapter test | ✅ |
| 0.13 | Capture golden fixtures from a live WordPress Complete site | `docker/laravel/app-skeleton/fixtures/` | 🟡 harness shipped (Laravel-captured); WP capture blocked on 4.6 |
| 0.14 | Document the env contract and rollback | this document | ✅ |

**Exit gate:** met — console boots against a stub/Laravel backend, fails loudly on a contract
mismatch, and all suites are green.

---

## 5. Phase 1 — T0 ops console (2 weeks)

| ID | Task | Files | Status |
|---|---|---|---|
| 1.1 | Settings: schema, masked values, validated update | `SettingsController`, `SettingsSchema` | ✅ |
| 1.2 | Diagnostics overview (bindings, versions, providers, queue) | `DiagnosticsController` | ✅ |
| 1.3 | Operations: cron/DLQ/retry/dismiss/timeline | `OperationsController`, `JobStatusStore` | ✅ |
| 1.4 | Chat dock against Laravel | existing `ChatStreamController` | ✅ (090 SSE) |
| 1.5 | Capability-filtered nav | `ConsoleSidebar.tsx`, session payload | ✅ |
| 1.6 | Laravel login/logout for C2 | `SessionController`, console `login.ts` | ✅ |
| 1.7 | Playwright smoke: login → 5 pages → chat | `e2e/` | ⏳ |
| 1.8 | Feature map → nav keys | `config/nvoos.php`, `features` endpoint | ✅ |

**Gate G1:** 5 T0 pages + chat render against Laravel; the network log shows zero WordPress
requests.

---

## 6. Phase 2 — T1 assistant studio (4–6 weeks)

| ID | Task | Files | Status |
|---|---|---|---|
| 2.1 | Durable assistant model + core contract service | `Assistant`, migration, `LaravelAssistantService` | ✅ |
| 2.2 | CRUD endpoints + default handling | `AssistantController` | ✅ |
| 2.3 | Bundle v1 export/import (core fields + full meta) | `AssistantPortabilityController` | ✅ Laravel-native. **Edit-form meta parity shipped** for 16 design-time keys via the additive `advanced` object on both runtimes (skills + progressive disclosure included), plus a base skills catalogue route and a Pro MCP-apps write route for the console — see [`090-b-assistant-edit-field-parity.md`](090-b-assistant-edit-field-parity.md) §4.1–4.2. Credentials, harness profiles, primary-role ID mapping and mesh routing remain explicit deferrals (§6 of that doc); the Laravel runtime hides the skills and MCP-apps pickers until it exposes those endpoints |
| 2.4 | Blueprints endpoints (catalogue, detail, install) | `BlueprintController` | ✅ catalogue + detail (`BlueprintTest`, 5 cases) — ⏳ install (§6.1) |
| 2.5 | Teams (basic CRUD + membership) — schema and contract in `090-g` §4.2 | `TeamController` | ✅ |
| 2.6 | Per-assistant tool assignment | `tools` field on the model | ✅ |
| 2.7 | Test-assistant dry run | `AssistantTestController` | ⏳ |
| 2.8 | Contract fixtures + round-trip tests | `AssistantStudioTest` | ✅ (shape/round-trip; WP fixtures ⏳) |

**Gate G2:** assistant create → export → import round-trip; a Service Exchange bundle imports and
the estate reaches `bundle_imported`.

### 6.1 Task 2.4 — Blueprints on Laravel

The console already ships `BlueprintsPage` and `api/blueprints.ts`; the Laravel
backend is the only missing half. WordPress serves the same three endpoints from
`WP_MCP_AI_Pro_Blueprints_REST_Controller`, which delegates to the promoted
`WP_MCP_AI_Unified_Blueprints_Page` seams:

| Endpoint | Payload |
|---|---|
| `GET /blueprints` | `{ total, toolkits: [{ slug, label, blueprints: [{ slug, name, description, tool_count, installed, assistant_id }] }] }` |
| `GET /blueprints/{toolkit}/{slug}` | `{ toolkit, slug, name, description, instructions, tools[], defaults }` |
| `POST /blueprints/{toolkit}/{slug}/install` | body `{ overwrite }` → `{ toolkit, slug, assistant_id }` |

**Where the data lives.** A blueprint is a JSON file at
`addons/pro/includes/tools/<toolkit>/examples/<slug>.json` (69 files today),
read through `WP_MCP_AI_Blueprint_Installer::list_blueprints()` and
`load_blueprint()`. A Laravel deployment does not ship `addons/`, and `lib/` is
excluded from wp.org builds, so neither tree can be the shared home.

Decision: **mirror the JSON into the Laravel app** at
`docker/laravel/app-skeleton/blueprints/<toolkit>/<slug>.json`, committed, with
a sync script and a CI drift check that fails when the two trees differ.
Duplication is explicit and tested rather than implicit.

**Shipped (read half).** `BlueprintController` serves the two GET routes from
`App\Services\BlueprintCatalogue`, reading the committed mirror — 69 blueprints
across 27 toolkits, synced by `bin/sync-blueprints.php` (which also takes
`--check` for the drift gate). Two deliberate differences from WordPress:
Laravel lists **every** mirrored toolkit, because there is no
`wp_mcp_ai_settings` equivalent to enable one; and the routes sit under
`can:manage_options` with the rest of the console, where WordPress gates on
`edit_posts`. Both are stated in the service docblock so they cannot be
mistaken for drift later.

**Sequencing.** Reads first, the same rule the 090-E families follow:

1. `GET /blueprints` and `GET /blueprints/{toolkit}/{slug}` — catalogue loader,
   toolkit enablement, detail normalisation, `BlueprintTest`.
2. `POST …/install` — only worth doing once the non-mechanical parts of
   `WP_MCP_AI_Blueprint_Installer::install()` have a home: profession slug →
   catalogue lookup (`find_profession_post_id()` becomes an adapter call), CRM
   meta remapping (`remap_crm_meta_to_canonical()`), `_blueprint_source`
   bookkeeping, and `overwrite` semantics.

**Installed map.** WordPress keys installed blueprints by assistant *title*
among assistants carrying `_blueprint_source`. Laravel should record the same
provenance and derive the map the same way, so both runtimes answer `installed`
and `assistant_id` identically.

**Tests.** `BlueprintTest` for shape, 404s and the enablement gate, plus a
contract fixture captured from the WordPress REST responses and replayed on
Laravel, the way the project-management corpus does it.

---

## 7. Phase 3 — T2 core operations (6–8 weeks)

| ID | Task | Contract | Status |
|---|---|---|---|
| 3.1 | Transcript store + endpoints | `TranscriptStoreInterface` | ✅ |
| 3.2 | Memory store (preferences, recall, store, update, delete, audit) | `MemoryStoreInterface` | ✅ |
| 3.3 | Session log store | `SessionLogStoreInterface` | ✅ (adapter; no console surface yet) |
| 3.4 | Token usage + cost aggregation | `TokenUsageInterface` | ✅ |
| 3.5 | Rate limiter adapter | `RateLimiterInterface` | ✅ |
| 3.6 | Approvals + orchestration control | `OrchestrationControlInterface` | ✅ list/create/approve/deny, 409 on double-resolve |
| 3.7 | Schedules + triggers | queue + cron status | ✅ trigger CRUD + toggle; ✅ schedule CRUD/toggle/trigger/history/results/fleet-stats |
| 3.8 | Analytics + performance monitor | `PerformanceMonitorInterface` | ✅ analytics; performance monitor ⏳ |
| 3.9 | Security center + audit log | audit store | ✅ posture/headers/snapshots/self-test (8 native signals — see §14) |
| 3.10 | Records + paper store | `ContentStoreInterface` + storage | ✅ Paper Store; ✅ content records via the `wp/v2` shim (posts/pages, list/get/create) |
| 3.11 | File upload/validation | `FileUploadServiceInterface` | ✅ MIME-sniffed, size-capped, media disk + `wp/v2/media`-shaped surface |

**Phase 3b notes**

- **Console limiter split.** Every console CRUD route originally shared
  `throttle:tools` (60/min). One console page fans out into several reads, so the
  budget starved the SPA and blocked sustained load tests. Console routes now use a
  `console` limiter (600/min per user, `NVOOS_CONSOLE_RATE_LIMIT`); actual tool
  execution keeps `throttle:tools`.
- **Snapshots never persist secrets.** `SettingsSchema::sensitiveKeys()` replaces
  provider credentials with the masked placeholder before the payload is stored, and
  `restore` skips that placeholder — a restore can never write `**************` over
  a live key.
- **Posture is a native subset.** The Laravel report ships 8 signals across the same
  four subtabs. The SPA parser is defensive, so it renders fewer cards rather than
  breaking; parity is tracked in §14.
- **Paper Store ids** are slugged and uniquified per collection (`same-title`,
  `same-title-2`), matching the WordPress store's read shape.

**Phase 3c notes**

- **The console's trigger path is `/orchestration/triggers`.** `runtime-config.ts` maps
  `triggers` to `${core}/orchestration/triggers`, not `${core}/triggers`. Both paths are
  now registered from one loop so they cannot drift again.
- **`wp/v2` is a shim, not an emulation.** The Records pages call `wp/v2/{restBase}`
  directly and paginate from `X-WP-Total` / `X-WP-TotalPages`. `Wp\ContentController`
  serves list/get/create for `posts` and `pages` from `nvoos_posts`; every other base
  answers a WP-style `rest_no_route` 404. Remote browsing and create-from-research
  answer 501 with a typed message rather than a bare 404.
- **Schedule runs are a real ledger.** `trigger` executes the schedule's handler through
  `ToolRegistry` synchronously and writes a `nvoos_schedule_runs` row, so history,
  results and fleet stats read from recorded runs instead of being synthesised.
- **Pro routes fail closed.** `EnsureProEntitlement` guards every `/api/v1/pro/*` route;
  the `deny` driver returns 402 `pro_entitlement_required`.
- **Octane keeps routes in memory.** After adding routes or classes on the FrankenPHP
  runtime, run `php artisan octane:reload` — `route:clear` alone leaves the live workers
  on the old route collection (see §13).

**Gate G3:** the contract suite is green for every enabled family; the auth matrix (admin/editor/
author/viewer/guest/bearer) passes on every mutating route.

**Phase 3d notes**

- **Uploads are MIME-sniffed, not extension-trusted.** `Laravel\Adapter\FileUploadService`
  keeps the core's allowlist (no SVG), caps at `NVOOS_UPLOAD_MAX_BYTES` (10 MiB default),
  and derives the stored extension from the sniffed type, so a renamed `.php` cannot land
  as executable content.
- **Media has its own disk.** `NVOOS_MEDIA_DISK` defaults to `public`; the general storage
  disk is often private. `Storage::url()` is normalised to an app-relative path so the
  console loads media from its own origin. The entrypoint runs `storage:link`.
- **Same `wp/v2` shape as content records.** `GET /api/v1/media` returns a JSON array with
  `X-WP-Total` / `X-WP-TotalPages`; `media_details.sizes` is empty, so the client falls back
  to `source_url` for every size (no thumbnail pipeline yet).
- **Config namespace is `oos`, not `nvoos`.** The provider merges the adapter config under
  `oos`; two Phase 3b/3c reads that used `nvoos.*` were corrected here (storage disk in the
  security self-test, console rate limit).

---

## 8. Phase 4 — T3 Pro families (engine 4–6 weeks + 2–4 per family)

| ID | Task | Files | Acceptance | Status |
|---|---|---|---|---|
| 4.1 | `ToolkitManifest` schema + validator + loader | `lib/core` + `App\Support\Pro` | Invalid manifests fail closed | ✅ |
| 4.2 | Five generic console screens (settings, entity workbench, research & add, consolidate, dashboard) | `addons/pro/assets/spa-v2/src/features/toolkits` | Screens render from a manifest | ✅ settings / entities / dashboard live; research + consolidate shipped as scaffolds |
| 4.3 | Generic resource controller + settings schema endpoint | `ToolkitController` | `/api/v1/pro/toolkits/{slug}` | ✅ list/show/settings/dashboard/entity CRUD |
| 4.4 | Enforce `ProEntitlement` on every Pro route | middleware | `deny` driver returns the typed error | ✅ route middleware + per-manifest feature checks |
| 4.5 | Pilot families: commerce, media, documents | manifests + services | G4 | 🟡 commerce + documents manifests shipped; **research drafting wired**; media is the built-in library |
| 4.6 | Migrate one family at a time; keep WP as a peer for T5 | manifests | No bespoke PHP pages | ✅ read surface at parity (6/6 fixtures, 0 drift); `research` ported and verified live |
| 4.7 | Contract fixtures per family | fixtures | Parity suite | ✅ capture command + 6 fixtures + shape replay + CI job |

**Phase 4 notes**

- **A toolkit is data.** `ToolkitManifest` validates slug/version/screens/settings/entities/
  dashboard and rejects unknown keys, dangling `entity:` references and unknown types, so a
  bad manifest is skipped and reported through `GET /api/v1/pro/toolkits` → `invalid`.
- **One controller, one table.** `nvoos_toolkit_records` stores `{toolkit, entity, external_id,
  data}`; filters and aggregates run through `json_extract`, which behaves the same on MySQL
  and SQLite. Entity payloads are validated against the manifest's columns (types, enums,
  required, unknown-field rejection), and the `id_field` is immutable after create.
- **Entitlement is per manifest, not per route.** The list filters unentitled families; direct
  access answers the typed 402 carrying the manifest's `feature`.
- **Remaining before G4:** the service layer behind the toolkits (4.5–4.7). The five console
  screens exist; `research` and `consolidate` are deliberate scaffolds (see §14).

**Phase 4.2 notes**

- **One shell, manifest-driven tabs.** `ToolkitPage` renders only the screens the manifest
  declares, in a fixed order, from a single `SCREEN_META` map — adding a screen to a manifest
  adds a tab with no host changes.
- **The client is typed.** `ToolkitsClient` raises `ToolkitsApiError` carrying `code` and `fields`,
  so the forms surface per-field validation messages from the backend rather than a generic
  banner. `available` is false when the runtime has no toolkit endpoint (the WordPress runtime
  today), and the nav entry is capability-gated so it simply does not appear there.
- **Screens own their data.** The shell owns the manifest and the active tab only; each screen
  loads its own slice, which keeps the entity table's paging/search independent of the
  dashboard aggregates.

**Gate G4:** a pilot toolkit installs from a manifest with zero bespoke PHP pages; paid surfaces
return the typed entitlement error under the `deny` driver.

---

## 9. Phase 5 — GA hardening (3–4 weeks)

| ID | Task | Acceptance |
|---|---|---|
| 5.1 | Wire the commercial entitlement source behind `ProEntitlement` (licence server instance ID or gateway/commerce) | ✅ `license` driver + `LicensedProEntitlement`; plan table shared with WordPress via `ProPlanCatalog` |
| 5.2 | Retire the `X-WP-Nonce` alias; the console sends Laravel's own CSRF header | ✅ Compatibility middleware deleted; console mirrors the token into `X-CSRF-TOKEN` |
| 5.3 | SSO/cookie-domain decision (same-origin proxy vs shared domain) | ✅ Folded into 5.2 — same-origin, no cookie domain needed |
| 5.4 | Rate limits, audit parity, observability, read-only profile | ✅ `ReadOnlyToolGuard` + security checklist below |
| 5.5 | Upgrade/migration path (schema versions, contract versioning) | ✅ `nvoos:doctor` gate + runbook below |
| 5.6 | 30-minute soak + performance gates | ✅ 22,486 requests, 0 errors; p95 0.587 s |

**Gate G5:** two consecutive releases with no contract drift; the nonce alias is gone.

### 5.1 implementation note — licence server, not a gateway

The blocker was that Pro entitlement looked WordPress-bound. It is not: the plugin's licence is a
plain HTTP contract, so the Laravel runtime reuses it and swaps the identity field.

| | WordPress | Laravel |
|---|---|---|
| Endpoint | `POST {server}/check` | same |
| Body | `license_key`, `site_url`, `product` | `license_key`, **`site_url` = `instance_id`**, `product` |
| Verdict | `status === 'valid'` | same, plus an `expires` sanity check |
| Plans | `compliance` / `professional` / `enterprise` | same table, via `ProPlanCatalog` |

- **One policy table.** `Nvoos\Core\Domain\Service\Entitlement\ProPlanCatalog` now owns the
  plan → feature map. `WP_MCP_AI_Pro_License::has_feature()` and
  `LicensedProEntitlement` both read it, so the tiers cannot drift again.
- **Stale-while-error, never stale-while-deny.** A definitive answer (valid *or* rejected) replaces
  history. Only a transport failure may re-serve the last known answer, and only from a store entry
  that outlives its own freshness window — expired cache keys are purged, so freshness is stored in
  the payload (`fresh_until`) rather than delegated to the store TTL.
- **Fail closed by default.** No key, no server, an unknown plan, a non-2xx, an unparseable body or
  a past `expires` all deny. Operator `features` overrides win in both directions but cannot
  resurrect a denied licence.
- **Diagnostics.** `LicensedProEntitlement::status()` exposes `plan`, `entitled`, `stale`,
  `checked_at` and the last error so the ops overview can explain an unavailable surface.

Config: `NVOOS_PRO_ENTITLEMENT_DRIVER=license` plus `NVOOS_PRO_LICENSE_KEY`;
`NVOOS_PRO_LICENSE_SERVER`, `_INSTANCE_ID`, `_PRODUCT`, `_TTL`, `_DENIED_TTL`, `_GRACE_TTL` and
`_TIMEOUT` cover the rest. The `allow` / `deny` / `config` drivers are unchanged.

### 5.2 implementation note — `X-CSRF-TOKEN`, not `X-XSRF-TOKEN`

Laravel **decrypts** `X-XSRF-TOKEN`, so that header only accepts the value of the `XSRF-TOKEN`
cookie. The console obtains its token from a JSON endpoint (`GET /api/v1/session/nonce`), not
from a cookie, so the correct header is `X-CSRF-TOKEN`, which `ValidateCsrfToken` compares
directly against the session token. Concretely:

- `AcceptWpNonce` middleware + its unit test deleted; `bootstrap/app.php` no longer registers it.
- `examples/nvoos-complete-velocity/src/fetch-wrapper.ts` mirrors the fetched nonce into
  `X-CSRF-TOKEN` (fetch wrapper **and** the jQuery `ajaxPrefilter`), guarded so it only fires when
  the token is non-empty and `X-CSRF-TOKEN` is absent. WordPress ignores the extra header, so one
  client build serves both runtimes.
- Coverage: `Phase1ConsoleTest` proves a bare `X-WP-Nonce` gets `419` and `X-CSRF-TOKEN` gets `200`.
  Both tests flip `app['env']` for the request because `VerifyCsrfToken::runningUnitTests()`
  short-circuits validation whenever the app reports `testing` — without the flip the suite can
  never observe a 419.

**5.3 is folded into 5.2.** The runtime is API-only and the console is served same-origin (Vite dev
proxy, `scripts/serve.mjs`), so no shared cookie domain or SSO proxy is needed. Revisit only if a
deployment serves the console from a different origin than the API.

### 5.4 implementation note — read-only profile + security checklist

**Rate limits** were already in place and are unchanged: `chat`, `mcp`, `tools` and `console`
limiters in `AppServiceProvider`, applied by route group. Pro routes inherit `throttle:console`
because they sit inside that group.

**Read-only profile** is the piece that was missing. `ReadOnlyToolGuard`
(`lib/core/src/Application/Tool/`) registers as a **monotonic deny-only guard** on `ToolRegistry`,
so it runs after every `tools/pre_execute` listener and no listener can undo a denial. Enabled by
`oos.read_only.enabled` (`NVOOS_READ_ONLY`).

| Knob | Default | Meaning |
|---|---|---|
| `gated_flags` | `write`, `state_changing`, `mutating`, `destructive`, `external_side_effect` | Flags that disqualify a tool |
| `allowed_slugs` | `[]` | Slugs that always run |
| `block_undeclared` | `true` | Deny tools that declare no flags |

Decision shape mirrors the WordPress `read-only` chat profile
(`WP_MCP_AI_Read_Only_Profile_Gate::is_tool_allowed()`): allowlist first, then a flag
intersection. It is deliberately **stricter in one direction** — a tool with no declared flags is
blocked by default. WordPress can lean on its flag table because the profile is scoped and
selectable; a runtime-wide switch cannot, and "I could not tell whether this writes" is not a
reason to let it write. Operators relax it with `block_undeclared: false` or an allowlist.

A blocked call returns the registry's existing `tool_guarded` envelope, so callers need no new
error handling.

#### Security checklist

Every WordPress control either has a Laravel counterpart or an explicit accepted gap.

| Control | WordPress | Laravel | State |
|---|---|---|---|
| Authentication | nonce, credential tokens, Auth0, guest tokens | Sanctum session cookie + bearer | ✅ |
| CSRF | `X-WP-Nonce` | `X-CSRF-TOKEN` (5.2) | ✅ |
| Authorization | `current_user_can` | WP-role matrix + `can:` gates per route group | ✅ |
| Rate limiting | request guard | four named limiters, per user/IP | ✅ |
| Read-only profile | `Read_Only_Profile_Gate` (profile-scoped) | `ReadOnlyToolGuard` (runtime-wide, 5.4) | ✅ |
| Cost tracking | cost tracker | `TokenUsage` + `/cost/*` | ✅ |
| Security posture | 21 signals | 8 native signals (`/security/posture`) | ✅ documented subset |
| Security headers | CSP headers | `/security/preview-headers` + posture signal | ✅ |
| Audit trail | audit logger + durable rows | `nvoos_jobs`, `nvoos_approvals` are durable | ⚠️ memory audit is a Phase 3 follow-up |
| Destructive-ops confirmation | destructive ops gate | not ported — no confirmation UI in the console | ⚠️ accepted (no destructive actions exposed) |
| Outbound URL guard | URL guard (SSRF) | outbound goes through `HttpClientInterface`; no allowlist | ⚠️ open — tracked separately |
| CORS | CORS guard | not configured — console is same-origin | ✅ not applicable |

The three ⚠️ rows are the honest remainder. None of them is reachable from a ported surface today;
if a later phase exposes a destructive action or an operator-supplied URL, its row must move to ✅
first.

### 5.5 implementation note — version gates and the upgrade runbook

Two version numbers, one gate:

| Version | Lives in | Meaning |
|---|---|---|
| `oos.contract_version` | config | The HTTP contract the console speaks. It asserts `mcp-ai/v1` + version 1 on boot and refuses to run otherwise. |
| `oos.schema_version` | config, mirrored into `nvoos_meta` | What the data means. Written by the migration that changes it. |

Laravel's `migrations` table already records *which* migrations ran. It does not record what the
data means, so an app pointed at an older database fails later with "column not found" somewhere
deep in a controller. `nvoos_meta` closes that gap.

`php artisan nvoos:doctor` is the gate. It checks container bindings, tools, providers, **contract
version**, **pending migrations** and the **schema version**, and exits non-zero on any failure — so
it can run as a post-deploy step rather than something a human has to read carefully.

#### Upgrade runbook

1. **Back up** the database (`php artisan nvoos:doctor` is read-only; `migrate` is not).
2. **Deploy the code** (`git pull`, `composer install --no-dev --optimize-autoloader`).
3. **Migrate**: `php artisan migrate --force`.
4. **Clear caches**: `php artisan optimize:clear`. Under Octane also run
   `php artisan octane:reload` — workers keep the old route collection and container in memory.
5. **Gate**: `php artisan nvoos:doctor` must exit `0`. A schema mismatch means step 3 did not run; a
   contract mismatch means the console build and the API are from different releases.
6. **Smoke**: `GET /api/v1/features` returns the expected `contract_version`.

Rolling back is `php artisan migrate:rollback --step=1` followed by redeploying the previous code;
`nvoos_meta` is dropped by its own `down()`, so a rollback leaves no version row behind to
confuse the next doctor run.

### 5.6 implementation note — soak and performance gates

The soak harness (`bin/laravel-spike-soak.php`) now records per-request latency, time-to-first-byte
and the HTTP status histogram, and exits non-zero on any error or on zero completions. TTFB is
drained when a handle completes — `spl_object_id()` is recycled once a handle is freed, so a probe
left in place makes every later handle inherit it and silently stop recording.

#### Gates

| Gate | Budget | Rationale |
|---|---|---|
| Errors | `0` | Any non-200 is a failure, not a blip |
| Latency p95 | ≤ 1.0 s | Total SSE stream time at 5 concurrent streams |
| Latency p99 | ≤ 1.5 s | Tail must not stall the console |
| TTFB p95 | ≤ 0.75 s | What a user actually feels before the first token |
| CPU | < 80 % | On the 2 vCPU dev box at 5 streams |

#### Formal run — 2026-11-13

5 concurrent SSE streams for 1800 s against `oos-laravel-app` (`/chat/stream`, spike provider):

```
started=22491  completed=22486  errors=0
statuses: 200:22486
done markers: 22486
bytes: 26,129,726 (24.9 MiB)
latency: p50=0.405s p95=0.587s p99=0.672s max=1.387s (n=22486)
```

The 5 uncompleted requests were in flight at the deadline and torn down by the harness. Every
completed request carried a `[DONE]` marker — no truncated streams. Container CPU settled at 45 %
and memory at 297 MiB; Redis and MySQL stayed under 5 %.

A 60 s baseline re-run with the fixed TTFB instrumentation:

```
latency: p50=0.403s p95=0.562s p99=0.642s max=0.793s (n=749)
ttfb:    p50=0.277s p95=0.405s p99=0.485s max=0.641s (n=749)
```

All five gates pass with headroom. Reproduce with:

```sh
php artisan nvoos:doctor                       # must exit 0 first
SPIKE_TOKEN=<token> php bin/laravel-spike-soak.php --streams=5 --seconds=1800
```

---

## 10. Contract fixture harness

Shipped (4.7). Fixtures live beside the skeleton and ship with it:

```
docker/laravel/app-skeleton/fixtures/
  mcp-ai-pro-v1/
    toolkits/index.json
    toolkits/commerce.json
    toolkits/commerce.settings.json
    toolkits/commerce.dashboard.json
    toolkits/commerce.entities.product.json
    toolkits/documents.json
```

- **Capture.** `php artisan fixtures:capture` runs the routes in-process through
the HTTP kernel with a short-lived Sanctum bearer token — the same path an
operator token uses — and writes `{ request, status, headers, body }` per route.
It reuses an existing administrator or creates and deletes a temporary one.
- **Refresh.** Capture into the runtime copy (`base_path('fixtures')`), then copy
the files back into `app-skeleton/fixtures/`: the repo mount is read-only inside
the container.
- **Replay.** `FixtureReplayTest` asserts the live response's **shape** (keys and
types, recursively) and its status — not values. Captured rows and totals do not
need to match the CI database, while a renamed key, a dropped field or a
stringified number fails.
- **CI.** `.github/workflows/laravel-app.yml` runs the suite on SQLite with the
same `phpunit.xml` the container uses, so the replay test gates every change
under `docker/laravel/**`, `lib/core/**` and `lib/laravel-adapter/**`.

The `mcp-ai-v1/` fixtures and a WordPress-captured set still depend on task 0.13
(a live site + operator token). Until 4.6 ports the toolkit controller to
WordPress, the toolkit fixtures are Laravel-captured — they pin the contract the
engine serves today, and are re-captured from WordPress when the peer lands.

---

## 11. File map (Phases 0–3b implementation)

**Core / adapter**

- `lib/core/src/Domain/Contract/ProEntitlementInterface.php` (new)
- `lib/laravel-adapter/src/Adapter/ProEntitlement.php` (new)
- `lib/laravel-adapter/src/Adapter/JobStatusStore.php` (`counts()`, raw rows, retry/dismiss)
- `lib/laravel-adapter/src/Adapter/{TranscriptStore,MemoryStore,SessionLogStore,TokenUsage,RateLimiter}.php` (new)
- `lib/core/src/Domain/ValueObject/ToolkitManifest.php` (new)
- `lib/laravel-adapter/src/Adapter/FileUploadService.php` (new)
- `lib/laravel-adapter/database/migrations/2026_01_01_000009..000012_*.php` (new)
- `lib/laravel-adapter/database/migrations/2026_01_01_000013..000016_*.php` (new — approvals, triggers, security snapshots, paper records)
- `lib/laravel-adapter/database/migrations/2026_01_01_000017..000018_*.php` (new — schedules console columns, schedule runs)
- `lib/laravel-adapter/database/migrations/2026_01_01_000019_create_nvoos_toolkit_records_table.php` (new)
- `lib/laravel-adapter/config/nvoos.php` (`pro_entitlement`, `features`)
- `lib/laravel-adapter/src/ServiceProvider/NvoosServiceProvider.php` (binding)
- `lib/laravel-adapter/tests/ProEntitlementTest.php` (new)

**Console**

- `examples/nvoos-complete-velocity/src/backend-profile.ts` (new)
- `examples/nvoos-complete-velocity/src/runtime-config.ts` (Laravel profile + assertion)
- `examples/nvoos-complete-velocity/src/main.tsx` (startup assertion)
- `examples/nvoos-complete-velocity/src/login.ts` (Laravel login/logout branch)
- `examples/nvoos-complete-velocity/scripts/serve.mjs` (`NVOOS_BACKEND`)
- `examples/nvoos-complete-velocity/src/__tests__/laravel-profile.test.ts` (new)
- `examples/nvoos-complete-velocity/src/__tests__/laravel-login.test.ts` (new)

**Console — Phase 4.2 toolkit screens**

- `addons/pro/assets/spa-v2/src/api/toolkits.ts` (`ToolkitsClient`, `ToolkitsApiError`)
- `addons/pro/assets/spa-v2/src/features/toolkits/{ToolkitsPage,ToolkitPage}.tsx`
- `addons/pro/assets/spa-v2/src/features/toolkits/screens/{Settings,Entities,Dashboard,Research,Consolidate}Screen.tsx`
- `addons/pro/assets/spa-v2/src/features/toolkits/components/EntityForm.tsx`
- `addons/pro/assets/spa-v2/src/features/toolkits/format.ts`
- `addons/pro/assets/spa-v2/src/styles/toolkits.css`
- `addons/pro/assets/spa-v2/src/{router.tsx,index.tsx,api/config.ts}` (routes, CSS import, endpoint)
- `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` (`toolkits` endpoint)
- `examples/nvoos-complete-velocity/src/console/{ConsoleShell.tsx,nav-features.tsx}` (routes + nav)
- `examples/nvoos-complete-velocity/src/runtime-config.ts` (Laravel-only endpoint)
- `examples/nvoos-complete-velocity/src/__tests__/toolkits-{client,format}.test.ts` (new)

**Laravel app skeleton**

- `app/Http/Controllers/{FeaturesController,SessionController,SiteController,ToolsController,OpsController}.php`
- `app/Http/Controllers/{SettingsController,DiagnosticsController,OperationsController}.php`
- `app/Http/Controllers/{TranscriptsController,MemoryController,AnalyticsController}.php`
- `app/Http/Controllers/{ApprovalsController,TriggersController,SecurityController,PaperStoreController}.php`
- `app/Http/Controllers/Pro/{SchedulesController,PresetsController,RecordsController}.php`
- `app/Http/Controllers/Wp/ContentController.php` (wp/v2 content shim)
- `app/Http/Controllers/MediaController.php` (wp/v2/media shim)
- `app/Support/Pro/ToolkitManifestLoader.php` + `app/Http/Controllers/Pro/ToolkitsController.php`
- `resources/pro-toolkits/{commerce,documents}.json`
- `app/Http/Middleware/EnsureProEntitlement.php`
- `app/Http/Controllers/{AssistantController,AssistantPortabilityController}.php`
- `app/Support/{CapabilityMap,SettingsSchema}.php`
- `app/Services/LaravelAssistantService.php`
- `app/Models/Assistant.php` + `database/migrations/2026_01_02_000001_create_assistants_table.php`
- `app/Http/Middleware/AcceptWpNonce.php`
- `routes/api.php`
- `bootstrap/app.php` (`statefulApi()`, CSRF alias)
- `tests/Feature/{Phase0ContractTest,Phase1ConsoleTest,AssistantStudioTest,CoreOperationsTest,Phase3bTest,Phase3cTest,Phase3dTest,Phase4Test}.php`
- `tests/Unit/AcceptWpNonceTest.php`

**Runtime plumbing (Phase 4.2)**

- `routes/api.php` — login/logout stay on the `api` group so Sanctum owns a single session;
  only `/session/nonce` keeps `web` (a GET always needs a session to mint from).
- `app/Http/Middleware/AcceptWpNonce.php` — pure header alias, registered on both groups. It
  runs before `StartSession`, so it must not compare against a session token;
  `ValidateCsrfToken` still performs the comparison.
- `bootstrap/app.php` — `statefulApi()` plus the alias on `web` and `api`.
- `scripts/serve.mjs` — keeps `Origin`/`Referer` for the Laravel backend; Sanctum's stateful
  check reads them (`NVOOS_BACKEND=wordpress` still strips them).
- `docker/laravel/entrypoint.sh` — idempotent `php artisan storage:link` for media uploads.

---

## 12. Test matrix and commands

| Layer | Command | Current |
|---|---|---|
| Adapter | `cd lib/laravel-adapter && php -d extension=fileinfo vendor/bin/phpunit` | 34 pass |
| Console | `cd examples/nvoos-complete-velocity && npx vitest run` | 132 pass |
| Console types | `npm run typecheck` | clean |
| Laravel app | `docker exec oos-laravel-app php artisan test` | 96 pass |
| Core | `cd lib/core && php -d extension=fileinfo vendor/bin/phpunit` | 937 tests (5 pre-existing `ToolRegistryIntegrationTest` errors) |
| Proxy syntax | `node --check examples/nvoos-complete-velocity/scripts/serve.mjs` | clean |

CI additions (Phase 1): run the adapter + console suites on PRs touching `lib/**` or the console;
run the Laravel app suite on PRs touching `docker/laravel/**`.

### Soak harness

`docker/laravel/soak/phase3b-soak.php` exercises every Phase 3b surface for a fixed
wall-clock duration and exits non-zero on any contract violation or when p95 latency
exceeds 500 ms.

```bash
# Mint a bearer token for the soak user
docker exec oos-laravel-app php artisan tinker \
  --execute="echo \App\Models\User::first()->createToken('soak')->plainTextToken;"

# 30-minute run at ~1 iteration/second (~10 requests/second)
php docker/laravel/soak/phase3b-soak.php \
  --base=http://127.0.0.1:8001 \
  --token='<sanctum-bearer>' \
  --minutes=30 --rate=1 \
  --out=docker/laravel/soak/phase3b-30min-report.json

# Clean up the append-only approval rows afterwards
docker exec oos-laravel-app php artisan tinker \
  --execute="\DB::table('nvoos_approvals')->where('session_id','soak')->delete();"
```

The harness creates and deletes its own paper records and reuses a single trigger,
so only approval history grows during a run.

---

## 13. Environment and deployment contract

| Variable | Owner | Values |
|---|---|---|
| `VITE_NVOOS_BACKEND` | Console build | `wordpress` (default), `laravel`, `hybrid` |
| `__NVOOS_BACKEND__` | Console runtime | Optional global override (tests, static-host injection); wins over the build env |
| `NVOOS_BACKEND` | `serve.mjs` | same values; must match the build |
| `NVOOS_TARGET_SITE` | `serve.mjs` | backend origin (WP or Laravel) |
| `NVOOS_PRO_ENTITLEMENT_DRIVER` | Laravel adapter | `allow`, `deny`, `config` |
| `NVOOS_PRO_PLAN` | Laravel adapter | plan label for diagnostics |
| `NVOOS_PRO_ENTITLEMENT_FEATURES` | Laravel adapter | JSON map for the `config` driver (optional) |
| `NVOOS_CONSOLE_RATE_LIMIT` | Laravel adapter | Console CRUD requests/minute per user (default `600`); tool execution keeps `throttle:tools` at 60/min |
| `NVOOS_MEDIA_DISK` | Laravel adapter | Disk for uploads (default `public`); must be publicly served |
| `NVOOS_UPLOAD_MAX_BYTES` | Laravel adapter | Maximum upload size in bytes (default `10485760`) |
| `NVOOS_TOOLKIT_MANIFESTS` | Laravel adapter | Directory of toolkit JSON manifests; empty falls back to `resources/pro-toolkits` |

**Deployment:** the Service Exchange deploys the same console artifact; `NVOOS_BACKEND=laravel` plus
`NVOOS_TARGET_SITE=<laravel-origin>` satisfies `console_deployed`. **Rollback:** flip
`NVOOS_BACKEND=wordpress` and the console returns to the WordPress estate — no rebuild.

**Runtime reload (Octane/FrankenPHP):** the container serves the app through Octane, which
keeps the booted application — including the route collection and resolved controllers —
in worker memory. After deploying new routes or classes, `php artisan octane:reload` is
required; `config:clear` / `route:clear` alone leave live workers answering from the old
route table (observed during the Phase 3c smoke: `/pro/schedules` worked while
`/pro/schedules/{id}/trigger` still 404'd until the reload).

---

## 14. Risks and open items

| Item | Status | Next |
|---|---|---|
| Golden fixtures from a live WP site | Harness shipped | Task 0.13; Laravel-captured set committed, re-capture from WordPress when 4.6 lands (needs an operator token) |
| Laravel login screen / bearer harness for C2 | **Done** | Phase 1; the console branches on the backend |
| Commercial entitlement source | Resolved in 5.1: licence server, `instance_id` instead of a site URL | Phase 5 task 5.1 ✅ |
| Pro toolkits as peers | Open (Q4) | Decide per family in Phase 4 |
| SSO / cookie domain | Open (Q6) | Phase 5 task 5.3 |
| Pro `mcp-ai-pro/v1` toolkit endpoints | Not started | Phase 4 |
| Laravel posture parity | Open | The Laravel report ships 8 native signals; WordPress ships 21. The SPA parser is defensive, so fewer cards render — decide in Phase 5 whether to expand or document the runtime-specific set |
| Records remote proxy + create-from-research | Not ported | Bound to Remote Sites and the Pro `deep_research` pipeline; both answer 501 with a typed message |
| Schedule presets | Not ported | `/api/v1/pro/presets` returns an empty catalogue; install answers 404 |
| Schedule execution model | Synchronous | `trigger` runs the handler inline and records the run; move to queued execution when long-running toolkits are ported |
| Media thumbnails / alt text | Not ported | `media_details.sizes` is empty and `alt_text` is write-once blank; add a thumbnail pipeline and an update route when the media toolkit is ported |
| WP-native integrations | Non-goal | Deep-link or mark unavailable |
| Pro tool catalogue on Laravel | **Decided (option 3)** | Selective port by cluster: 18 tools are dependency-free, 143 need one adapter, 860 stay in the plugin. Tracker + recipe: `090-d-selective-tool-port.md`; regenerate with `bin/classify-tool-portability.php` |
| Toolkit `research` screen | **Done (4.5)** | `POST /pro/toolkits/{slug}/research` (Laravel) and `POST …/toolkit-manifests/{slug}/research` (WordPress) both call the shared core service: the prompt is built from the manifest's columns and the draft is filtered back to them. The draft is never written by the service — the console saves it through the create endpoint, so one validation path governs writes. No provider → typed `ai_unavailable` 503. |
| Toolkit `consolidate` screen | Bounded | Scans the first 100 records of each entity (one page) to keep the request O(1). Move to a queued job when a family needs full-catalogue dedupe |
| `/pro/toolkits` on the WordPress runtime | **Done** | `mcp-ai-pro/v1/toolkit-manifests` serves the same envelope as Laravel — verified route-by-route against the committed fixtures (6/6, 0 drift). `research` is ported too: both runtimes share `Nvoos\Core\Application\Toolkit\ToolkitResearchService`. |
| Non-stateful session login | Decided | A POST to `/session/login` without a first-party `Referer`/`Origin` answers a typed 419 `session_required` instead of a 500 (browser-only flow) |

---

## 15. Phase checklist

- [x] Phase 0 — contract freeze, runtime profile, assertion, T0 endpoints, CSRF alias
- [x] Phase 1 — T0 ops console (Playwright smoke pending)
- [x] Phase 2 foundation — assistant CRUD + bundle export/import (blueprints/teams/test-assistant pending)
- [x] Phase 3a — transcripts, memory, session logs, token usage/cost, rate limiter
- [x] Phase 3b — approvals, workflow triggers, security center (posture/headers/snapshots/self-test), paper store
- [x] Phase 3c — schedule manager, records schema, `wp/v2` content shim, Pro entitlement middleware
- [x] Phase 3d — media library + upload service (task 3.11)
- [x] Phase 4.1 + 4.3 — toolkit manifest contract, loader, generic engine
- [x] Phase 4.2 — generic toolkit console screens (settings, workbench, dashboard, consolidate live; research scaffold replaced in 4.5)
- [x] Phase 4.5 — toolkit research service: draft-from-query endpoint + console wiring (draft only; saving still goes through `storeEntity`)
- [x] Phase 4.7 — contract fixtures: `fixtures:capture`, per-family fixtures, shape replay, CI job
- [x] Phase 4.6 — WordPress peer: read surface at parity (6/6 fixtures) and `research` ported through the shared core service
- [ ] Phase 5 — commercial entitlement, CSRF migration, GA gates
