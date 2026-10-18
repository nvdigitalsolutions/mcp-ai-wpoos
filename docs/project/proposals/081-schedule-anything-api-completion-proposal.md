# Proposal 081 — Schedule Anything: Backend API Completion (Presets + Toolkits + Contract Alignment)

- **Date:** 2026-10-18
- **Status:** Draft (research complete, awaiting approval)
- **Surfaces:** `addons/pro` (new REST controller + preset registry), `addons/schedule-anything-platform` (toolkit adapter reuse), `addons/schedule-anything-spa` (payload alignment only, no new pages)
- **Tracks:** schedule-anything SaaS, REST API, multi-tenancy
- **Follows:** PR #7121 (SPA packaging wiring + nonce fix), the E2E smoke run on the `sa-e2e` Docker stack

## 1. Problem Statement

The E2E smoke test of the Schedule Anything SPA against a live WP 6.9 Multisite
proved the shell, auth, schedules list, builder canvas, analytics, and booking
stub all work — but surfaced three concrete gaps:

1. **G1 — Presets page blank.** `GET /mcp-ai-pro/v1/presets` returns
   `rest_no_route` 404. The SPA hook
   (`addons/schedule-anything-spa/src/hooks/usePresets.ts`) documents the
   intended backend — "read from the `WP_MCP_AI_Pro_Schedule_Presets` registry,
   installed via the Schedule Manager's `create_schedule` method" — but that
   class was **never built** (grep-verified across `addons/pro`).
2. **G2 — Settings page blank.** `GET /mcp-ai-pro/v1/toolkits` and
   `POST /mcp-ai-pro/v1/toolkits/{slug}/toggle` are unregistered. The data
   layer already exists: `SA_Toolkit_Manager` in the platform plugin exposes
   `get_all_statuses()` returning exactly the SPA's expected
   `{slug, flag_key, enabled}` shape and `toggle_toolkit()` with the
   canonical `TOOLKIT_FLAGS` slug→flag map (18+ toolkits, tier-based defaults).
   Only the REST surface is missing.
3. **G3 — Create-schedule payload misalignment.** The E2E POST exercise
   showed the REST create endpoint enforces a richer schema than the SPA
   builder posts (`name`, `schedule` object, task `hook`, cron `interval`
   etc.), producing a cascade of 400/500 validation errors. The two sides
   were built independently and were never contract-tested against each other.

Goals:

- **G1/G2 closed** by registering the missing endpoints with the exact
  response shapes the SPA already expects (no SPA rewrite).
- **G3 closed** by aligning the create payload + surfacing server validation
  errors in the SPA form.
- Preset install is **idempotent** (industry-standard; see §2.3).
- Every endpoint obeys the repo's REST/security conventions (permission
  callbacks, canonical envelope, two-gate sanitisation, tenant scoping).

Non-goals for this proposal: the booking backend (plan Phase 3 — Redis SET NX
slots, payment), Cloud Worker billing flows, and the production CORS/deploy
runbook (listed as a checklist in §9, not built here).

## 2. Research & Industry Standards

### 2.1 Multi-tenant SaaS API principles

- **Verified tenant context is a platform primitive** — "centralize
  tenant-context resolution so application code consumes a trusted context
  rather than interpreting raw request parameters" (Frontegg,
  [SaaS Multi-Tenancy best practices](https://frontegg.com/blog/saas-multitenancy)).
  In our stack the primitive already exists: WordPress Multisite, where the
  tenant **is** the current blog. Therefore the new endpoints must derive the
  tenant from the current site (no client-supplied tenant/slug parameter) and
  must never cross blogs with unvalidated input.
- **Server-side enforcement of feature flags/entitlements** — "Feature flags
  and entitlement checks should be enforced on the server side, especially
  when they control data access or paid capabilities" (Qrvey,
  [multi-tenant architecture guide](https://qrvey.com/blog/multi-tenant-saas-architecture/));
  "store feature entitlements as a configuration object per tenant, check
  features at both the API and UI layers, and never hard-code plan logic into
  business rules" (Zelifcam,
  [multi-tenant scaling guide](https://www.zelifcam.net/blog/the-zelifcam-difference-1/building-multi-tenant-saas-architecture-decisions-that-scale-49)).
  Consequence: `toolkits/toggle` validates the slug against the server-side
  catalogue **and** the tier-derived entitlement defaults (`SA_Toolkit_Manager::
  get_defaults_for_tier()`); the SPA never decides what's allowed.
- **Defense in depth** — "include tenant scope in queries, cache keys, and
  storage boundaries" and "test negative isolation paths continuously"
  (OWASP [Multi-Tenant Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html)).
  Consequence: presets/toolkits read from per-site options only
  (`wp_mcp_ai_settings` is a per-blog option in multisite); test suites assert
  cross-tenant non-leakage.

### 2.2 WordPress REST API standards

- Every route carries a `permission_callback`; **never** `__return_true` on
  state-changing routes (WordPress REST API Handbook,
  [Adding Custom Endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/);
  the wp.org plugin-review rule the repo already follows).
- Declare `args` schemas with `sanitize_callback` + `validate_callback`
  (StackHarbor,
  [custom endpoints guide](https://stackharbor.com/en/knowledge-base/wp-rest-api-custom-endpoints/));
  core handles `X-WP-Nonce` validation (WP Pioneer,
  [custom endpoints guide](https://wppioneer.com/for-developers/custom-rest-api-endpoints-wordpress/)).
- Return `WP_REST_Response` or `WP_Error` only — matches the repo's canonical
  envelope rule (success array with `ok`, `WP_Error` otherwise) and the
  existing `mcp-ai-pro/v1/schedules` controller conventions.

### 2.3 Idempotent install mutations

- POST installs must be safe to retry: "stable id keys so retries and
  reprocessing do not duplicate writes" (ThinkBot,
  [API integration best practices](https://thinkbot.agency/blog/api-integration-best-practices-saas-api-changes));
  Stripe's
  [idempotency-key semantics](https://stripe.com/blog/idempotency) — same key
  + same fingerprint → return the original result; different fingerprint with
  a claimed key → 409.
- Consequence: `POST /presets/{id}/install` derives a deterministic key from
  `preset_id + overrides` hash, stores it on the created schedule's meta,
  and returns the existing schedule (200 `ok`) on replay instead of creating a
  duplicate.

## 3. Design Decisions

| # | Decision | Alternatives | Rationale |
|---|---|---|---|
| D1 | Register all four routes in the **Pro addon** under `/mcp-ai-pro/v1` | Platform plugin under `/nvoos-saas/v1`; base plugin | The SPA (and its committed dist) calls `/mcp-ai-pro/v1/*`; no SPA change. Pro already owns `schedules` under this namespace with the same envelope. |
| D2 | Data access via a thin adapter: `SA_Toolkit_Manager` when the platform plugin is active, Pro settings options otherwise | Duplicate flag map in Pro | One canonical slug→flag map (platform's `TOOLKIT_FLAGS`); avoids drift. `class_exists` gate keeps the Pro addon standalone-capable. |
| D3 | Tenant = current blog; no tenant param | Slug/ID in request | Trusted context primitive (§2.1). Cross-tenant reads stay in the platform's API-key-gated controller. |
| D4 | Read caps = `read` (any authenticated user in the tenant); write caps = `manage_options` (tenant admin), with a `wp_mcp_ai_sa_manage_capability` filter seam | Custom caps | Least privilege without inventing caps in v1; the filter is the documented hook for role granularity (plan Phase "user mgmt" later). |
| D5 | Preset catalogue: static registry `WP_MCP_AI_Pro_Schedule_Presets` (the class the SPA comment already names), seeded per toolkit; platform-tier filters applied at query time when platform is active | DB-backed preset CPT; platform-only catalogue | Deterministic, testable, ships with the addon; tier gating is a query filter, not catalogue duplication. |
| D6 | Install = registry preset + overrides → `WP_MCP_AI_Pro_Schedule_Manager::create_schedule()` with a deterministic idempotency key | Direct CPT writes | Reuses the manager's validation chain (the E2E-proven 400/500 rules) — one validation authority. |
| D7 | Response envelope `{ ok: true, ... }` everywhere, `WP_Error` on failure | REST-perfect responses | Matches SPA types (`{ok, presets, total}`, `{ok, toolkits}`) and repo canonical-envelope sniffs. |

## 4. API Contracts (exact — driven by `usePresets.ts` + `useSchedules.ts`)

All routes: tenant-scoped to the current blog; auth via WP cookie + `X-WP-Nonce`.

### 4.1 `GET /mcp-ai-pro/v1/toolkits`

- Permission: `read` (any logged-in user in the tenant).
- Query: none (v1; `search` reserved).
- Response `200`:
  ```json
  { "ok": true, "toolkits": [ { "slug": "crm", "flag_key": "enable_crm_toolkit", "enabled": true } ] }
  ```
- Source: `SA_Toolkit_Manager::get_all_statuses()` when platform active,
  else the same map resolved against `wp_mcp_ai_settings` in Pro.

### 4.2 `POST /mcp-ai-pro/v1/toolkits/{slug}/toggle`

- Permission: `manage_options` (filterable seam, D4).
- Body: `{ "enabled": true }` (boolean; `rest_sanitize_boolean`).
- Validation: `slug` must exist in the flag map AND be enabled for the
  tenant's tier entitlement (starter defaults) — unknown slug → `WP_Error`
  404; tier-locked slug → 403 `sa_toolkit_not_entitled` (message names the
  tier requirement).
- Response `200`: `{ "ok": true, "slug": "...", "enabled": true }`.

### 4.3 `GET /mcp-ai-pro/v1/presets`

- Permission: `read`.
- Query args (all optional, sanitized): `search` (text), `category`,
  `toolkit` (slug), `page`/`per_page` (absint, cap 50).
- Response `200`: `{ "ok": true, "presets": [Preset], "total": 42 }` where
  `Preset` = the SPA interface: `id, name, description, toolkit, category,
  icon, schedule_type, schedule, tags[], schedule_data`.
- Tier filtering when platform active: `SA_Toolkit_Manager::get_presets_for_tier()`.

### 4.4 `GET /mcp-ai-pro/v1/presets/{id}`

- Permission: `read`. Unknown id → `WP_Error` 404 `sa_preset_not_found`.

### 4.5 `POST /mcp-ai-pro/v1/presets/{id}/install`

- Permission: `manage_options` (D4).
- Body: `{ "overrides": { ... } }` — merged shallowly over the preset's
  `schedule_data` before create.
- Idempotency (D6): key = `preset:{id}:h{sha256(overrides-json)}`; stored in
  schedule meta `_sa_install_key`. Replay with the same fingerprint returns
  `200 { "ok": true, "schedule": <existing>, "replayed": true }`; the
  `create_schedule` validation chain produces `WP_Error` (surfaced verbatim —
  the E2E-proven messages) on bad overrides.
- Response `201` first time: `{ "ok": true, "schedule": <redacted>, "preset_id": "..." }`.

### 4.6 G3 alignment — create schedule contract

- Document the create schema (from the existing controller): `name`
  (required), `schedule_type`, `schedule` (required object), `hook` +
  `timestamp` for task-type, `interval` (registered WP cron interval) for
  recurring, workflow steps for workflow-type.
- SPA side: the Builder's onSave must emit that exact shape, and the
  create/update mutation surfaces `WP_Error.message` into the form (sonner
  toast + inline), so server validation is visible instead of a silent blank.

## 5. Implementation Plan (phased, each independently shippable)

### Phase 1 — Toolkits REST surface (smallest, unblocks Settings)

- `addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-sa-toolkit-adapter.php`
  — `SA_Toolkit_Manager` adapter (D2) with the standalone fallback.
- `addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php`
  — routes 4.1 + 4.2; args schemas; permission callbacks (D4).
- Registration via the existing pro REST init (`rest_api_init` hook used by
  `class-wp-mcp-ai-pro-schedule-rest-controller.php` — mirror its loader).
- PHPUnit: `addons/pro/tests/test-sa-toolkit-rest.php` (permission matrix
  read/write/nopriv, slug validation, tier-locked 403, toggle persistence,
  cross-tenant isolation: value set on blog A invisible on blog B).

### Phase 2 — Presets registry + read endpoints

- `addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-schedule-presets.php`
  — static catalogue (id → Preset), `get_presets( args )` with search/
  category/toolkit/pagination, `get_preset( id )`, tier filter hook.
  Seed an initial catalogue: ≥ 3 presets per flagship toolkit (calendar-
  booking, crm, ecommerce, social-media, analytics, document-generation) —
  the Builder's tool palette is the source list.
- Routes 4.3 + 4.4 in the Phase-1 controller.
- PHPUnit: `addons/pro/tests/test-schedule-presets-registry.php` (catalogue
  integrity: unique ids, required fields, valid toolkit slugs against the
  adapter map; pagination math; search matching).

### Phase 3 — Install endpoint

- Route 4.5: overrides merge (whitelist keys — reject unknown override keys
  with `WP_Error` 400), idempotency meta, manager hand-off.
- PHPUnit: `addons/pro/tests/test-schedule-presets-install.php` (happy path
  creates exactly one schedule; replay returns same id + `replayed:true`;
  overrides merge correctly; invalid overrides surface the manager's error;
  permission matrix).

### Phase 4 — Contract alignment + SPA error surfacing

- Builder onSave payload per 4.6; `useCreateSchedule`/`useUpdateSchedule`
  error propagation to sonner + field errors.
- vitest: extend `addons/schedule-anything-spa` suites — payload-shape unit
  tests + a mocked-error-surfacing test for the form.
- Re-run the E2E walk (sa-e2e stack): Settings toggles persist across
  reload; Presets list renders; install → schedule appears in Schedules;
  duplicate install replays instead of duplicating.

### Phase 5 — Release bookkeeping

- Changelog (root + Pro), `docs/tool-reference.md` entries for the two new
  tool-surface routes (they are not MCP tools — REST only, so no registry
  surfaces), README updates for the SPA/platform, and the proposal's §9
  deploy checklist folded into the platform README.

## 6. Testing Strategy

- **PHPUnit (Docker, WP 6.9 + 7.1):** Phases 1–3 suites above; follow the
  `mcp-ai-wpoos-test-suite` conventions (bootstrap REST controller in
  `setUp()`, fixture discipline, permission matrices).
- **Negative isolation paths (OWASP §2.1):** every suite includes a
  cross-tenant test — data written via the endpoint on blog A is absent from
  the same endpoint on blog B (`switch_to_blog` in tests).
- **SPA vitest:** payload-shape + error-surfacing tests (Phase 4); keep the
  23/23 baseline green.
- **E2E:** the documented sa-e2e stack walk (Phase 4) becomes the recurring
  release gate for the schedule-anything surface.
- **phpcs:** both standards (`WordPress` + `PHPCompatibilityWP` 7.4–8.3) on
  every new file; the custom canonical-envelope + sanitise-at-entry sniffs
  apply (severity 5).

## 7. Security Checklist

- [ ] `permission_callback` on all four routes; state-changing routes never
      public (D4, §2.2).
- [ ] `args` schemas with `sanitize_callback` + `validate_callback`;
      `enabled` boolean-coerced; ids `sanitize_key`/`absint`.
- [ ] Two-gate sanitisation on the toolkit adapter outputs (escape at exit).
- [ ] Preset override keys whitelisted per preset schema — no mass assignment.
- [ ] Tenant scope = current blog only; no client-supplied tenant (D3).
- [ ] Idempotency meta stored on the created schedule; replay never
      double-writes (D6, §2.3).
- [ ] Nonce handled by core (`X-WP-Nonce`); the platform nonce-endpoint fix
      from PR #7121 is the SPA handshake (no new auth surface).
- [ ] Audit logging via the base plugin's audit logger for install + toggle
      events (tenant, user, preset slug, result).

## 8. Risks & Open Questions

1. **Tier gating data:** `get_defaults_for_tier()`/`get_presets_for_tier()`
   are seeded per tenant; Pro-standalone installs (no platform plugin) have
   no tier concept — the fallback treats all toolkits as entitled. Acceptable
   for v1; document in the adapter.
2. **Preset catalogue size:** static catalogue ships with the addon; updates
   are code releases. A DB-backed/customizable catalogue is a future
   enhancement (do not build now — YAGNI).
3. **Capability granularity:** v1 pins tenant-admin writes to
   `manage_options` with a filter seam. Role-based granularity lands with the
   plan's "user mgmt" phase; the seam must not be removed.

## 9. Deployment Checklist (production readiness, not built in this proposal)

- [ ] `cors_allowed_origins` includes the SPA origin (never "Allow All").
- [ ] `wp_mcp_ai_cors_guard_route_prefixes` widened to include
      `/mcp-ai-pro/v1` and `/nvoos-saas/v1`.
- [ ] `SA_SAAS_API_KEY` set; platform healthz green (`pro_active`,
      `schedule_manager`).
- [ ] Edge cache exclusion for session-bound payloads (the nonce fix added
      no-cache headers; extend the same to any user-scoped response).
- [ ] Static host serves `assets/dist/` with SPA fallback (`try_files …
      /index.html`), `/wp-json` proxied to WP (the sa-e2e nginx conf is the
      reference config).

## 10. References

- Frontegg — SaaS Multi-Tenancy Explained: https://frontegg.com/blog/saas-multitenancy
- OWASP — Multi-Tenant Security Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html
- Qrvey — Multi-Tenant SaaS Architecture: https://qrvey.com/blog/multi-tenant-saas-architecture/
- Zelifcam — Multi-Tenant SaaS Architecture Decisions: https://www.zelifcam.net/blog/the-zelifcam-difference-1/building-multi-tenant-saas-architecture-decisions-that-scale-49
- WordPress REST API Handbook — Adding Custom Endpoints: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- Stripe — Designing Robust APIs with Idempotency: https://stripe.com/blog/idempotency
- ThinkBot — SaaS API Integration Best Practices: https://thinkbot.agency/blog/api-integration-best-practices-saas-api-changes
- Repo ground truth: `addons/schedule-anything-spa/src/hooks/usePresets.ts`,
  `useSchedules.ts`; `addons/schedule-anything-platform/includes/class-sa-toolkit-manager.php`;
  `addons/pro/includes/rest/class-wp-mcp-ai-pro-schedule-rest-controller.php`;
  `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` (nonce precedent);
  `docs/project/plans/schedule-anything-saas-implementation.md`.
