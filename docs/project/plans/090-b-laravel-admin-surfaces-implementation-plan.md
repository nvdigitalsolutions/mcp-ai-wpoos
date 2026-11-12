# Proposal 090-B — Laravel Admin Surfaces: Implementation Plan

**Status:** Phases 0–3d implemented and verified — the base console and the Phase 3 Pro surfaces run on the Laravel adapter (2026-11-12) · Phases 4–5 planned
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
| `X-WP-Nonce` CSRF compatibility | **Done** | `app/Http/Middleware/AcceptWpNonce.php` |
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
| Settings bound to dashboard filters | **Done (Phase 4.5)** | `ToolkitManifest` filter lists + `filter.setting`; `low_stock` card consumes `low_stock_threshold` |
| Server-side consolidation | **Done (Phase 4.5)** | `Pro\ToolkitsController::duplicates` + `ConsolidateScreen` (whole entity, not one page) |
| Bulk import with dry run | **Done (Phase 4.5)** | `Pro\ToolkitsController::importEntity`, `ImportDialog`, `parseDelimitedRows` |
| Per-family contract suite | **Done (Phase 4.7)** | `tests/Feature/ToolkitContractTest.php` over every shipped manifest |
| WordPress toolkit surface | **Done (Phase 4.6)** | `addons/pro/includes/toolkits/**`, `Pro\Toolkit_REST_Controller`, `toolkits` endpoint in the SPA config |
| Console session/CSRF regression fixes | **Done** | single session store on login (`routes/api.php`), `AcceptWpNonce` header alias, stateful test origin |

**Validation record (2026-11-12):**

- Adapter PHPUnit: **34 tests, 101 assertions**
- Console Vitest: **14 files, 142 tests**
- Console typecheck: clean; `lint:a11y` clean
- Laravel app PHPUnit: **108 tests, 788 assertions**
- Core PHPUnit: **945 tests, 2,029 assertions** — 29 `ToolkitManifestTest` tests pass; the 5 `ToolRegistryIntegrationTest` errors are the pre-existing abstract-class discovery bug
- Console smoke (Laravel runtime, 2026-11-12): login → catalogue → Commerce settings / workbench create / **bulk import (preview 2 ready + 1 duplicate, then commit)** / **server-side duplicate scan** / dashboard with the setting-bound `low_stock` card — all against the MySQL-backed app at `:8001`
- WordPress Pro suite (2026-11-12): **16 tests** — `test-pro-toolkit-rest.php` (14) mirrors the Laravel contract suite, `test-pro-spa-config-toolkits-endpoint.php` (2) pins the endpoint gating
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
| 0.13 | Capture golden fixtures from a live WordPress Complete site | `docker/laravel/fixtures/` | ⏳ |
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
| 2.3 | Bundle v1 export/import (core fields + full meta) | `AssistantPortabilityController` | ✅ (Laravel-native; WP meta parity ⏳) |
| 2.4 | Blueprints endpoints (catalogue, detail, install) | `BlueprintController` | ⏳ |
| 2.5 | Teams (basic CRUD + membership) | `TeamController` | ⏳ |
| 2.6 | Per-assistant tool assignment | `tools` field on the model | ✅ |
| 2.7 | Test-assistant dry run | `AssistantTestController` | ⏳ |
| 2.8 | Contract fixtures + round-trip tests | `AssistantStudioTest` | ✅ (shape/round-trip; WP fixtures ⏳) |

**Gate G2:** assistant create → export → import round-trip; a Service Exchange bundle imports and
the estate reaches `bundle_imported`.

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
| 4.5 | Pilot families: commerce, media, documents | manifests + services | G4 | ✅ commerce + documents ship settings-bound cards, server-side duplicates and bulk import; media remains the built-in library |
| 4.6 | Migrate one family at a time; keep WP as a peer for T5 | manifests | No bespoke PHP pages | ✅ WordPress now serves the same contract at `mcp-ai-pro/v1/toolkits` off `addons/pro/includes/toolkits/manifests/` |
| 4.7 | Contract fixtures per family | fixtures | Parity suite | ✅ `ToolkitContractTest` drives every shipped manifest through catalogue → settings → lifecycle → dashboard → duplicates → import |

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

**Phase 4.5/4.7 notes**

- **Settings are no longer inert.** A dashboard filter may bind to a declared setting
  (`{"column":"stock","op":"lte","setting":"low_stock_threshold"}`), and `filter` may be a list
  (ANDed). An unset/null setting disables that condition instead of filtering everything out, so
  a half-configured toolkit still reports honest numbers. The `low_stock` card is the proof.
- **Consolidation is a query, not a page.** `GET …/entities/{entity}/duplicates?column=` groups
  the whole entity (`GROUP BY <json path> HAVING COUNT(*) > 1`), caps the groups and says when it
  truncated. The screen no longer loads records to scan them.
- **Import is dry-run first.** `POST …/entities/{entity}/import` accepts up to 500 rows and
  returns the same validation the form uses. With `preview` it writes nothing. `duplicates`
  counts *skipped* rows only, so `valid + duplicates + failed` always equals the batch size.
  The dialog parses pasted tab/comma/pipe sheets client-side (pure, unit-tested) and refuses to
  import before a preview has passed.
- **One portable JSON path.** MySQL's `json_extract` returns a quoted JSON string, so grouping and
  comparing on it silently misses every row; SQLite does not. `jsonPath()` wraps MySQL in
  `json_unquote()`, which is the difference between the duplicate scan working and returning an
  empty list. Tests run on SQLite, so this is only visible on a real MySQL app.
- **The contract suite is the parity gate.** `ToolkitContractTest` loops every manifest in
  `resources/pro-toolkits`; a new family is covered the moment its manifest lands, and a manifest
  that declares something the engine cannot serve fails CI.

**Phase 4.6 notes — WordPress parity**

- **Same contract, two runtimes.** `mcp-ai-pro/v1/toolkits…` mirrors every Laravel route and the
  same `{ success, data }` / `{ success, error: { code, message, fields? } }` envelope, so the
  shared screens cannot tell the backends apart. The manifests live in
  `addons/pro/includes/toolkits/manifests/` and are validated by the same `lib/core` class.
- **One table, installed on demand.** `{prefix}nvoos_toolkit_records` is created by `dbDelta`
  when the `wp_mcp_ai_pro_toolkit_db_version` option moves, so fresh activations and in-place
  plugin updates both work without a re-activation step.
- **Admin-only.** Every route requires `manage_options`; the SPA config only emits the endpoint
  for admins, so non-admins never see the nav entry.
- **MySQL quoting.** WordPress is always MySQL/MariaDB, where `json_extract` returns a quoted
  JSON string; `json_path()` wraps it in `json_unquote()` for every comparison, group and
  aggregate.
- **Parity is tested, not assumed.** `test-pro-toolkit-rest.php` mirrors the Laravel contract
  suite case for case (14 tests), and `test-pro-spa-config-toolkits-endpoint.php` pins the
  endpoint gating.

**Gate G4:** a pilot toolkit installs from a manifest with zero bespoke PHP pages; paid surfaces
return the typed entitlement error under the `deny` driver.

---

## 9. Phase 5 — GA hardening (3–4 weeks)

| ID | Task | Acceptance |
|---|---|---|
| 5.1 | Wire the commercial entitlement source behind `ProEntitlement` (licence server instance ID or gateway/commerce) | Sold Laravel Pro resolves entitlements |
| 5.2 | Migrate the SPA to `X-XSRF-TOKEN`; remove the `X-WP-Nonce` alias | Compatibility middleware deleted |
| 5.3 | SSO/cookie-domain decision (same-origin proxy vs shared domain) | Documented + tested |
| 5.4 | Rate limits, audit parity, observability, read-only profile | Security checklist green |
| 5.5 | Upgrade/migration path (schema versions, contract versioning) | One documented upgrade |
| 5.6 | 30-minute soak + performance gates | 0 errors; p95 within budget |

**Gate G5:** two consecutive releases with no contract drift; the nonce alias is gone.

---

## 10. Contract fixture harness

```
docker/laravel/fixtures/
  mcp-ai-v1/
    features.json
    session-me.json
    tools.json
    assistants/list.json
  mcp-ai-pro-v1/
    ops-overview.json
    toolkits/commerce.json
```

- Capture: a read-only script hits the WordPress Complete site with an operator token and writes
  `{ request, status, headers, body }` per route.
- Replay: Laravel feature tests assert the response shape against the fixture (keys, types, envelope,
  pagination), not byte equality.
- CI: a fixture diff job fails when a WP contract changes without a corresponding Laravel update.

Phase 0 shape tests stand in until a live site is available for capture (task 0.13).

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
- `addons/pro/assets/spa-v2/src/features/toolkits/components/ImportDialog.tsx`
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
- `tests/Feature/{Phase0ContractTest,Phase1ConsoleTest,AssistantStudioTest,CoreOperationsTest,Phase3bTest,Phase3cTest,Phase3dTest,Phase4Test,ToolkitContractTest}.php`
- `tests/Unit/AcceptWpNonceTest.php`

**WordPress Pro (Phase 4.6)**

- `addons/pro/includes/toolkits/class-wp-mcp-ai-pro-toolkit-manifests.php`
- `addons/pro/includes/toolkits/class-wp-mcp-ai-pro-toolkit-records.php`
- `addons/pro/includes/toolkits/manifests/{commerce,documents}.json`
- `addons/pro/includes/rest/class-wp-mcp-ai-pro-toolkit-rest-controller.php`
- `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` (module `rest_toolkits`)
- `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` (`toolkits` endpoint)
- `addons/pro/tests/test-pro-toolkit-rest.php` + `test-pro-spa-config-toolkits-endpoint.php`

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
| Console | `cd examples/nvoos-complete-velocity && npx vitest run` | 142 pass |
| Console types | `npm run typecheck` | clean |
| Laravel app | `docker exec oos-laravel-app php artisan test` | 108 pass |
| WordPress (Pro toolkit) | `bin/run-tests-docker.sh addons/pro/tests/test-pro-toolkit-rest.php` | 14 pass |
| WordPress (SPA config) | `bin/run-tests-docker.sh addons/pro/tests/test-pro-spa-config-toolkits-endpoint.php` | 2 pass |
| Core | `cd lib/core && php -d extension=fileinfo vendor/bin/phpunit` | 945 tests (5 pre-existing `ToolRegistryIntegrationTest` errors) |
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
| Golden fixtures from a live WP site | Pending | Task 0.13; needs an operator token |
| Laravel login screen / bearer harness for C2 | **Done** | Phase 1; the console branches on the backend |
| Commercial entitlement source | Decided as a seam; source open | Phase 5 task 5.1 |
| Pro toolkits as peers | Open (Q4) | Decide per family in Phase 4 |
| SSO / cookie domain | Open (Q6) | Phase 5 task 5.3 |
| Pro `mcp-ai-pro/v1` toolkit endpoints | Not started | Phase 4 |
| Laravel posture parity | Open | The Laravel report ships 8 native signals; WordPress ships 21. The SPA parser is defensive, so fewer cards render — decide in Phase 5 whether to expand or document the runtime-specific set |
| Records remote proxy + create-from-research | Not ported | Bound to Remote Sites and the Pro `deep_research` pipeline; both answer 501 with a typed message |
| Schedule presets | Not ported | `/api/v1/pro/presets` returns an empty catalogue; install answers 404 |
| Schedule execution model | Synchronous | `trigger` runs the handler inline and records the run; move to queued execution when long-running toolkits are ported |
| Media thumbnails / alt text | Not ported | `media_details.sizes` is empty and `alt_text` is write-once blank; add a thumbnail pipeline and an update route when the media toolkit is ported |
| WP-native integrations | Non-goal | Deep-link or mark unavailable |
| Toolkit `consolidate` screen | Fixed | Server-side `GROUP BY` over the whole entity; only the number of groups is capped |
| MySQL vs SQLite JSON semantics | Fixed | MySQL returns quoted JSON from `json_extract`; `jsonPath()` adds `json_unquote()` on MySQL. Tests run on SQLite, so keep a MySQL smoke in the loop for JSON-path changes |
| Toolkit `research` screen | Scaffold | Renders the manifest's prompt + existing records; returns no AI drafting because the Laravel runtime has no `deep_research` pipeline. Bulk add now lives in the workbench instead |
| `/pro/toolkits` on the WordPress runtime | **Done (Phase 4.6)** | Served at `mcp-ai-pro/v1/toolkits`; the SPA config emits it for admins |
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
- [x] Phase 4.2 — generic toolkit console screens (settings, workbench, dashboard live; research + consolidate scaffolds)
- [x] Phase 4.5 + 4.7 — settings-bound cards, server-side consolidation, dry-run bulk import, per-family contract suite
- [x] Phase 4.6 — WordPress serves the same toolkit contract (`mcp-ai-pro/v1/toolkits`)
- [ ] Phase 5 — commercial entitlement, CSRF migration, GA gates
