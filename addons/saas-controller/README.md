# NV oOS SaaS Controller Addon

**Operator-side toolkit to deploy and manage the NV oOS Cloud control plane** (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter) from inside WP-Admin.

This addon is the operator-side counterpart to `addons/cloud-worker/`. Where `cloud-worker` is the deployed runtime, the **SaaS Controller** is the WordPress plugin that lets a maintainer **provision, plan/apply changes to, drift-check, and audit** that runtime — without leaving WP-Admin.

> **Status:** v0.1.0 — Phases 2, 3, 4, 5a, 5b, 5c, 5d, 6, 7, 8, 9, 10 & 11 landed (WP-Admin & REST plumbing + credentials wizard with live preflight + read-only Reconcile-Plan generator + audit log & smoke tester + HITL-gated Apply step + drift detector + Worker upload + Stripe / OpenRouter mutating surfaces + Stripe webhook receiver + background async Apply + orphan cleanup + webhook events admin UI).

## What's available today (Phases 2 / 3 / 4 / 5a / 5b / 5c / 5d / 6 / 7 / 8 / 9 / 10 / 11)

- **Top-level admin menu** — `WP-Admin → NV oOS SaaS` (capability: `manage_options`) with four tabs:
  - **Overview** — interactive React **Credentials Wizard** (Credentials → Validate → Save) plus a static masked-credentials table fallback for no-JS environments.
  - **Deployment** — desired Cloudflare topology editor (Worker name, account ID override, AI Gateway slug, D1 databases, KV namespaces) plus a **Run Plan** button that calls `POST /nvoos-saas/v1/plan` and renders the structured plan in-place. Read-only — no mutation occurs on this tab.
  - **Operations** — **Apply (HITL-gated)** panel (Preview → Apply, single-use 15-minute token, per-resource result table), **Drift Detector** panel (Run Drift Check → colour-coded synced/drift/unknown/error verdict + pinned-vs-deployed fingerprint table), **Review Orphans** panel (per-row checkboxes, separate single-use token), **Webhook Events** panel (paginated table + Refresh + Clear), **Run Smoke Tests** button (live Cloudflare workers list + plan dry-run + base-plugin liveness), and the most recent 50 audit-log entries with a **Clear Audit Log** action. A red drift banner is also rendered admin-wide on every NV oOS SaaS screen whenever the cached drift state is `drift`.
  - **Packages** — in-product credits surface listing every bundled npm dependency with upstream homepage, license, and copyright.
- **Encrypted credential store** (`nvoos_saas_controller_credentials` option) — AES-256-CBC at rest, derived from `AUTH_KEY + SECURE_AUTH_KEY`. Allowed keys: `cloudflare_account_id`, `cloudflare_api_token`, `stripe_secret_key`, `stripe_webhook_secret`, `openrouter_api_key`.
- **Deployment-config store** (`nvoos_saas_controller_deployment` option) — desired Cloudflare topology, persisted as plaintext JSON (no secrets). Per-field sanitisation enforces Worker-name slug rules and Workers binding identifier rules (`[A-Z][A-Z0-9_]*`).
- **Connection tester** (`NVOOS_SaaS_Controller_Connection_Tester`) — performs read-only HTTPS preflights against Cloudflare (`/accounts/{id}`), Stripe (`/v1/account`), and OpenRouter (`/auth/key`). 10 s per-request timeout, normalised `{ ok, latency_ms, status, message }` shape, never echoes secrets.
- **Cloudflare client** (`NVOOS_SaaS_Controller_Cloudflare_Client`) — read-only wrapper around `GET /accounts/{id}/d1/database`, `…/storage/kv/namespaces`, `…/workers/scripts`, `…/ai-gateway/gateways`. No mutation methods exist on this client; the Apply step uses a separate mutating client (below) behind the HITL gate.
- **Mutating Cloudflare client** (`NVOOS_SaaS_Controller_Cloudflare_Mutating_Client`) — exposes only the writes the Apply step needs: `POST …/d1/database`, `POST …/storage/kv/namespaces`, `POST …/ai-gateway/gateways`, and `PUT …/workers/scripts/{name}` (multipart module-worker upload, Phase 5d). Each call records exactly one entry in the audit log (success or failure). The upload helper also surfaces Cloudflare's response `etag` so the Apply step can persist a runtime drift fingerprint.
- **Apply engine** (`NVOOS_SaaS_Controller_Apply_Engine`) — consumes a plan and applies its `creates[]` and `updates[]` to Cloudflare under a single-use HITL token. The token is a 32-byte URL-safe random string; only its SHA-256 hash is persisted (as a transient with a 15-minute default TTL, filterable via `nvoos_saas_controller_apply_token_ttl`). Returns per-resource result rows (`{ kind, target, status: ok|error|skipped, message, detail? }`) plus an aggregate summary. `kind=worker` rows (in either `creates[]` or `updates[]`) trigger a multipart upload of `worker/dist/index.js` to Cloudflare with D1 / KV / `AI_GATEWAY_SLUG` bindings derived from the deployment config (filterable via `nvoos_saas_controller_worker_dist_path`, `nvoos_saas_controller_worker_compatibility_date`, and `nvoos_saas_controller_worker_upload_metadata`). On success the engine writes `{ worker_name, sha256, etag, size, uploaded_at }` to option `nvoos_saas_controller_deployed_worker`, which the drift detector reads as a fallback when the on-disk manifest is unstamped. Non-worker `updates[]` rows are still skipped (in-place edits of D1/KV/AI Gateway are not modelled). **Phase 10**: `plan.orphans[]` rows can now be deleted explicitly via the separate `POST /apply/orphans/preview` + `POST /apply/orphans/run` HITL surface — orphan tokens use a distinct `nvoos_saas_orphan_` transient namespace so the orphan-delete button can never be confused with the regular Apply button. Stripe rows are archived (`active=false`) because Stripe forbids permanent deletion of products/prices once they have transaction history; everything else is permanently deleted.
- **Drift detector** (`NVOOS_SaaS_Controller_Drift_Detector`) — compares the deployed Cloudflare Worker against a pinned fingerprint. The pin is read from `worker/drift-manifest.json` first (`{ expected_sha256, expected_etag, version, built_at, worker_dist_path }`) and falls back to the `nvoos_saas_controller_deployed_worker` option written by Apply when the manifest is unstamped. Comparison precedence is **etag** (Cloudflare's own content fingerprint) then **sha256** (response-body hash). Returns `{ ok, status: synced|drift|unknown|error, source: manifest|deployed_option, worker_name, expected_sha256, expected_etag, actual_sha256, actual_etag, message, duration_ms, ts }`, persists the result in option `nvoos_saas_controller_last_drift_check`, and records one audit-log entry per run on channel `internal`. On a fresh install with no manifest pin and no prior Apply run, both fingerprints are `null` and the detector returns `status=unknown` (never `drift`) — guaranteeing no false-positive banners.
- **Plan generator** (`NVOOS_SaaS_Controller_Plan_Generator`) — diffs the desired config against live Cloudflare state. Output is a structured plan with `creates` / `updates` / `noops` / `orphans` / `errors` arrays plus a `summary` count map. Cloudflare API failures are recorded in `errors[]` (never thrown), so a partial network outage on one section still produces a useful plan for the rest. **Phase 6**: also accepts optional Stripe and OpenRouter clients and emits `kind=stripe_product`, `kind=stripe_price`, and `kind=openrouter_key` rows under the same plan shape; sections whose credentials aren't configured are silently skipped.
- **Stripe client** (`NVOOS_SaaS_Controller_Stripe_Client`, Phase 6) — list + idempotent create for products and prices. Products use Stripe's client-supplied-id semantics (re-runs return the existing product); prices use the documented `Idempotency-Key` header derived from the desired-config tuple so an operator-side change becomes a new idempotency key. Each mutation records one entry on the `stripe` audit-log channel.
- **OpenRouter client** (`NVOOS_SaaS_Controller_OpenRouter_Client`, Phase 6) — list + create runtime keys via the OpenRouter Provisioning API (`/api/v1/keys`). Requires a separate `openrouter_provisioning_key` credential (the regular runtime key has no scope over `/keys`). The plaintext value of a newly created key is surfaced exactly once in the apply result row's `detail.key` field and is never persisted by the addon.
- **Audit log** (`NVOOS_SaaS_Controller_Audit_Log`) — append-only ring buffer (option `nvoos_saas_controller_audit_log`, last 200 entries). Each entry records `{ ts, actor_id, actor, channel, action, target, status, latency_ms, message, request_id }`. Channels are constrained to `cloudflare`/`stripe`/`openrouter`/`internal`. Filterable via `nvoos_saas_controller_audit_log_max_entries` and `nvoos_saas_controller_audit_log_record` (return `false` to suppress an entry). The Cloudflare client records one entry per API call automatically.
- **Smoke tester** (`NVOOS_SaaS_Controller_Smoke_Tester`) — runs four read-only checks in sequence: (1) Cloudflare credential presence, (2) live `list_workers` call, (3) plan dry-run against the current desired config, (4) base-plugin liveness. Returns `{ ok, checks[], duration_ms, ts }`; the last result is cached in `nvoos_saas_controller_last_smoke_test`. Each check writes one entry to the audit log.
- **Stripe webhook verifier** (`NVOOS_SaaS_Controller_Stripe_Webhook_Verifier`, Phase 7) — stateless verifier that reproduces Stripe's official library algorithm: parses the `Stripe-Signature` header (`t=…,v1=…`), recomputes the HMAC-SHA256 of `{timestamp}.{raw_body}` against the stored `stripe_webhook_secret`, and accepts only when at least one `v1=` value matches in constant time (`hash_equals`). Default tolerance window is 300 seconds — outside that window, deliveries are rejected as replays. Multiple `v1=` values are honoured (Stripe ships them during a secret rotation). Returns a stable structured verdict: `{ ok, reason, timestamp, event_id, event_type }`.
- **Webhook event store** (`NVOOS_SaaS_Controller_Webhook_Event_Store`, Phase 7) — append-only ring buffer (option `nvoos_saas_controller_webhook_events`, last 200 entries; filterable via `nvoos_saas_controller_webhook_events_max_entries`). Idempotent by `provider` + `event_id` so Stripe retries do not flood the buffer. Stores only `event.id`, `event.type`, the provider-supplied event timestamp, signature status, and a short message — never PII (no customer email, billing address, or card-fingerprint data). **Phase 11**: surfaced under the Operations tab as a **Webhook Events** card — paginated table (event_id / type / ts / signature status / message), Refresh button, and Clear Events button (which calls `DELETE /webhooks/events`, a route that records its own audit-log entry before clearing).
- **Background async Apply** (`NVOOS_SaaS_Controller_Apply_Job`, Phase 8) — queued, cron-tick driven worker that consumes a single-use `apply_token` and processes a previewed plan **one row per tick**, so a multi-DB + KV + Stripe + Worker-upload apply never hits `max_execution_time` on shared hosts. Each tick pops one row, dispatches it through `Apply_Engine::apply_row()`, persists a structured result, and re-schedules itself via `wp_schedule_single_event`. State (queue + accumulated `results[]` + `summary` + `errors[]`) is held in a 6 h transient (filterable via `nvoos_saas_controller_apply_job_state_ttl`); a hard `MAX_TOTAL_ROWS` ceiling (200) bounds a single job. The synchronous `/apply/run` route is still available for small applies.
- **Background-apply admin UI** (Operations tab, Phase 9) — a "Run in background" checkbox next to the Apply button switches the operator from the synchronous `POST /apply/run` path to `POST /apply/enqueue`, then renders a live progress card (HTML `<progress>` bar + processed/total + summary counters + `last_message`) by polling `GET /apply/jobs/{id}` every 2 seconds. A Cancel button is shown while the job is `queued|running` and calls `POST /apply/jobs/{id}/cancel`. Polling stops on the terminal states `completed | cancelled | failed` and the partial results table is replaced with the final result rows.
- **REST namespace** `/wp-json/nvoos-saas/v1/` (every route requires `manage_options` + REST nonce **except `POST /webhooks/stripe`**, which is signature-gated):
  - `GET    /healthz` — addon version + base-plugin liveness probe.
  - `GET    /credentials` — masked snapshot (never returns plaintext).
  - `POST   /credentials` — set/update one or more credentials.
  - `DELETE /credentials` — clear all credentials.
  - `POST   /connections/test` — run live preflight against the three providers (uses supplied values or falls back to stored).
  - `GET    /deployment` — current desired config.
  - `POST   /deployment` — replace desired config (JSON body).
  - `POST   /plan` — run the reconcile-plan generator against live Cloudflare state.
  - `GET    /audit-log` — paginated audit-log entries (newest first; `?limit=&offset=`).
  - `DELETE /audit-log` — clear the audit log (records its own audit entry first).
  - `POST   /smoke-tests/run` — execute the smoke-test sequence.
  - `GET    /smoke-tests/last` — most recent cached smoke-test result.
  - `POST   /apply/preview` — re-run the plan against live Cloudflare and issue a single-use HITL `apply_token` (15-minute TTL). Returns 409 if the plan reports any errors.
  - `POST   /apply/run` — consume an `apply_token` and execute its cached plan against Cloudflare. Returns `{ ok, results[], summary, duration_ms, ts }`. 410 if the token is unknown/expired, 409 if it has already been used.
  - `POST   /apply/enqueue` — Phase 8. Consume an `apply_token` and enqueue a background apply job. Returns `{ ok, job: { id, status: queued, total, processed: 0, ... } }`. Same single-use token semantics as `/apply/run`.
  - `GET    /apply/jobs/{id}` — Phase 8. Poll a background apply job's progress projection (`{ status, total, processed, percent, summary, results[], errors[], last_message, created_at, updated_at }`). 404 if the job is unknown or its 6 h state transient has expired.
  - `POST   /apply/jobs/{id}/cancel` — Phase 8. Cancel a queued or running apply job. An already-firing tick will finish its current row before the cancelled status is observed.
  - `POST   /drift/check` — run a fresh drift check against the deployed Worker. Always returns 200 with the structured drift result (transport-level errors surface as `status=error`).
  - `GET    /drift/last` — most recent cached drift-check result, or `{ status: 'unknown', message: ... }` if none has run yet.
  - `POST   /webhooks/stripe` — **public, signature-gated** (Phase 7). Verifies the `Stripe-Signature` header against `stripe_webhook_secret`. Returns 200 fast on first delivery and on Stripe-driven retries (idempotent by `event.id`); 401 on missing/mismatched/replayed signatures; 400 on malformed payloads; 412 if the secret is not configured. Recorded summary is written to the webhook event store and mirrored once to the audit log on the `stripe` channel.
  - `GET    /webhooks/events` — paginated webhook event list (newest first; `?limit=&offset=`).
  - `DELETE /webhooks/events` — clear the webhook event store (records its own audit-log entry first).
- **Drift-manifest stamping** (`scripts/stamp-drift-manifest.mjs`, Phase 5e) — invoked automatically by `npm run build:worker` (and therefore by `bin/build-addon-zips.sh`). Computes `sha256(worker/dist/index.js)`, reads `version` from `package.json`, and writes both — plus an ISO `built_at` timestamp — into `worker/drift-manifest.json` so a fresh release ZIP always ships with a pinned fingerprint before any Apply has run. `expected_etag` stays `null` until Apply records the Cloudflare-returned etag (etags can only be observed post-deploy, so the build never invents one). Pass `npm run check:drift-manifest` (which calls the script with `--check`) to verify in CI without rewriting the file.

## Features (planned)

_All Phase 1–11 features are now implemented; this section is a placeholder for future enhancements._

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS base plugin (active)
- Node.js 18.17+ (build-time only, never required at runtime)

## Installation

1. Upload the `nvoos-saas-controller` folder to `/wp-content/plugins/`.
2. Activate the plugin through the WordPress admin.
3. Navigate to **NV oOS → SaaS Controller** to launch the wizard.

## Architecture — npm Package Buckets

Every npm dependency in this addon falls into one of three buckets:

| Bucket | Where it lives | Ships in distribution ZIP? |
|---|---|---|
| **A. Worker build-time** | `worker/src/` → bundled to `worker/dist/index.js` via esbuild | Built artifact only — `node_modules/` excluded |
| **B. Admin UI runtime** | `assets/src/` → built to `assets/build/` via `@wordpress/scripts` | Built bundle only — sources excluded |
| **C. Dev tooling** | `devDependencies` in `package.json` | Never |

WordPress.org compliance: `node_modules/` is never shipped. Only the compiled artifacts under `assets/build/` and `worker/dist/` are included in the release ZIP.

## Build

```bash
cd addons/saas-controller
npm ci
npm run build           # builds both Worker and Admin UI bundles
npm run build:worker    # esbuild → worker/dist/index.js
npm run build:admin     # @wordpress/scripts → assets/build/
npm run typecheck       # tsc --noEmit
npm run lint:js         # eslint via @wordpress/scripts
npm run test            # jest via @wordpress/scripts
npm run worker:dryrun   # wrangler deploy --dry-run (no live publish)
```

The repo-wide `bin/build-addon-zips.sh` orchestrates this for releases and emits `build/nvoos-saas-controller-vX.Y.Z.zip`.

## Credits

This addon bundles the following third-party JavaScript libraries at runtime. Full per-package metadata (version, license, copyright, homepage) is in [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md), and the canonical repo-wide index is in the root [`CREDITS.md`](../../CREDITS.md).

| Package | License | Purpose |
|---|---|---|
| [`@tanstack/react-query`](https://tanstack.com/query) | MIT | Polling reconcile-job status, drift results, audit log. |
| [`zod`](https://zod.dev/) | MIT | Client-side schema validation of credentials & reconcile-plan JSON. |
| [`diff`](https://github.com/kpdecker/jsdiff) | BSD-3-Clause | Plan-preview before/after rendering. |
| [`date-fns`](https://date-fns.org/) | MIT | Audit-log timestamps and "last checked X ago" labels. |
| [`clsx`](https://github.com/lukeed/clsx) | MIT | Conditional className helper. |

WordPress core externals (`@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/data`, `@wordpress/icons`, `@wordpress/url`) are loaded from WP-Admin and are not bundled.

Build-time and dev-only packages (e.g. `wrangler`, `esbuild`, `@cloudflare/workers-types`, `@wordpress/scripts`, `typescript`, `miniflare`) are not shipped — see [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) for the full inventory.

## License

**Proprietary — © 2026 NV Digital Solutions. All rights reserved.**

Unlike the rest of the NV oOS repository (which is GPLv3), this addon is
proprietary software of NV Digital Solutions. It is **not** licensed under
the repo-root `LICENSE` (GPLv3) and is **not** distributed via WordPress.org.
Use, reproduction, modification, and redistribution are governed by the
addon-local [`LICENSE`](LICENSE) file in this directory.

The third-party packages listed in [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)
remain under their own upstream licenses (MIT / BSD-3-Clause / Apache-2.0 /
GPL-2.0-or-later as applicable); their inclusion does not relicense any
NV Digital Solutions code.
