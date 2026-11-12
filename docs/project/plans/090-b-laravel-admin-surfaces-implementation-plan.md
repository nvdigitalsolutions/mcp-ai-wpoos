# Proposal 090-B — Laravel Admin Surfaces: Implementation Plan

**Status:** Phases 0–1 implemented and verified; Phase 2 assistant-studio foundation implemented (2026-11-12) · Phases 3–5 planned
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
| Golden fixtures captured from WordPress | **Pending** | Shape tests stand in; capture requires a live Complete site |
| Playwright T0 smoke (G1) | **Pending** | Phase 1 task 1.7 |
| Blueprints/teams/test-assistant | **Pending (Phase 2)** | See §6 |
| Core ops adapters, Pro manifests | **Pending (Phases 3–4)** | See §7–§8 |

**Validation record (2026-11-12):**

- Adapter PHPUnit: **34 tests, 101 assertions**
- Console Vitest: **12 files, 112 tests**
- Console typecheck: clean
- Laravel app PHPUnit: **34 tests, 141 assertions**
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

| ID | Task | Contract | Acceptance |
|---|---|---|---|
| 3.1 | Transcript store + endpoints | `TranscriptStoreInterface` | list/get/export |
| 3.2 | Memory store (preferences, facts, search) | `MemoryStoreInterface` | Console Memory surfaces |
| 3.3 | Session log store | `SessionLogStoreInterface` | Session history |
| 3.4 | Token usage + cost tracking | `TokenUsageInterface`, `CostTrackingServiceInterface` | Analytics numbers match usage |
| 3.5 | Rate limiter adapter | `RateLimiterInterface` | Per-ability limits |
| 3.6 | Approvals + orchestration control | `OrchestrationControlInterface` | Approve/reject flow |
| 3.7 | Schedules + triggers | queue + cron status | Schedule Manager works |
| 3.8 | Analytics + performance monitor | `PerformanceMonitorInterface` | Analytics page |
| 3.9 | Security center + audit log | `ErrorTrackingServiceInterface` + audit | Security page |
| 3.10 | Records + paper store | `ContentStoreInterface` + storage | Records/Paper Store pages |
| 3.11 | File upload/validation | `FileUploadServiceInterface`, `FileValidationServiceInterface` | Media upload works |

**Gate G3:** the contract suite is green for every enabled family; the auth matrix (admin/editor/
author/viewer/guest/bearer) passes on every mutating route.

---

## 8. Phase 4 — T3 Pro families (engine 4–6 weeks + 2–4 per family)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| 4.1 | `ToolkitManifest` schema + validator + loader | `lib/core`/app support | Invalid manifests fail closed |
| 4.2 | Five generic console screens (settings, entity workbench, research & add, consolidate, dashboard) | `addons/pro/assets/spa-v2/src/features` | Screens render from a manifest |
| 4.3 | Generic resource controller + settings schema endpoint | `ToolkitController` | `/api/v1/pro/toolkits/{slug}` |
| 4.4 | Enforce `ProEntitlement` on every Pro route | middleware | `deny` driver returns the typed error |
| 4.5 | Pilot families: commerce, media, documents | manifests + services | G4 |
| 4.6 | Migrate one family at a time; keep WP as a peer for T5 | manifests | No bespoke PHP pages |
| 4.7 | Contract fixtures per family | fixtures | Parity suite |

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

## 11. File map (Phases 0–2 implementation)

**Core / adapter**

- `lib/core/src/Domain/Contract/ProEntitlementInterface.php` (new)
- `lib/laravel-adapter/src/Adapter/ProEntitlement.php` (new)
- `lib/laravel-adapter/src/Adapter/JobStatusStore.php` (`counts()`, raw rows, retry/dismiss)
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

**Laravel app skeleton**

- `app/Http/Controllers/{FeaturesController,SessionController,SiteController,ToolsController,OpsController}.php`
- `app/Http/Controllers/{SettingsController,DiagnosticsController,OperationsController}.php`
- `app/Http/Controllers/{AssistantController,AssistantPortabilityController}.php`
- `app/Support/{CapabilityMap,SettingsSchema}.php`
- `app/Services/LaravelAssistantService.php`
- `app/Models/Assistant.php` + `database/migrations/2026_01_02_000001_create_assistants_table.php`
- `app/Http/Middleware/AcceptWpNonce.php`
- `routes/api.php`
- `bootstrap/app.php` (`statefulApi()`, CSRF alias)
- `tests/Feature/{Phase0ContractTest,Phase1ConsoleTest,AssistantStudioTest}.php`
- `tests/Unit/AcceptWpNonceTest.php`

---

## 12. Test matrix and commands

| Layer | Command | Current |
|---|---|---|
| Adapter | `cd lib/laravel-adapter && php -d extension=fileinfo vendor/bin/phpunit` | 34 pass |
| Console | `cd examples/nvoos-complete-velocity && npx vitest run` | 112 pass |
| Console types | `npm run typecheck` | clean |
| Laravel app | `docker exec oos-laravel-app php artisan test` | 34 pass |
| Proxy syntax | `node --check examples/nvoos-complete-velocity/scripts/serve.mjs` | clean |

CI additions (Phase 1): run the adapter + console suites on PRs touching `lib/**` or the console;
run the Laravel app suite on PRs touching `docker/laravel/**`.

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

**Deployment:** the Service Exchange deploys the same console artifact; `NVOOS_BACKEND=laravel` plus
`NVOOS_TARGET_SITE=<laravel-origin>` satisfies `console_deployed`. **Rollback:** flip
`NVOOS_BACKEND=wordpress` and the console returns to the WordPress estate — no rebuild.

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
| WP-native integrations | Non-goal | Deep-link or mark unavailable |

---

## 15. Phase checklist

- [x] Phase 0 — contract freeze, runtime profile, assertion, T0 endpoints, CSRF alias
- [x] Phase 1 — T0 ops console (Playwright smoke pending)
- [x] Phase 2 foundation — assistant CRUD + bundle export/import (blueprints/teams/test-assistant pending)
- [ ] Phase 3 — core operations (transcripts, memory, tokens, approvals, analytics, records)
- [ ] Phase 4 — Pro manifest engine + pilot families
- [ ] Phase 5 — commercial entitlement, CSRF migration, GA gates
