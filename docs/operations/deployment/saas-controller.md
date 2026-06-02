# NV oOS SaaS Controller

**Version:** 0.1.0  
**Location:** `addons/saas-controller/`  
**Requires:** WordPress 6.0+, PHP 7.4+, NV oOS base plugin (active)  
**Admin entry point:** `WP-Admin → NV oOS SaaS` (`manage_options`)

The SaaS Controller is the operator-side WordPress admin toolkit for provisioning and managing the **NV oOS Cloud control plane** — Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, and OpenRouter — without leaving WP-Admin.

It is the **operator-facing counterpart** to `addons/cloud-worker/`: where the cloud worker is the runtime you deploy to `nvoos.cloud`, the SaaS Controller is the admin plugin that lets you provision it, reconcile desired vs live state, apply changes under a Human-in-the-Loop gate, detect drift, and audit every mutation.

**Full implementation reference:** [`addons/saas-controller/README.md`](../addons/saas-controller/README.md)

---

## Admin UI

A top-level **NV oOS SaaS** menu item is added for any user with `manage_options`. It contains four tabs:

| Tab | Purpose |
|-----|---------|
| **Overview** | React Credentials Wizard (Credentials → Validate → Save) with a masked-credentials table fallback for no-JS environments |
| **Deployment** | Desired Cloudflare topology editor (Worker name, account ID override, AI Gateway slug, D1 databases, KV namespaces) + read-only **Run Plan** button |
| **Operations** | HITL-gated Apply, Drift Detector, Orphan Review, Webhook Events, Smoke Tests, and the 50 most recent audit-log entries (with **Clear Audit Log**). A red drift banner is rendered admin-wide whenever the cached drift state is `drift`. |
| **Packages** | In-product credits surface — upstream homepage, license, and copyright for every bundled npm dependency |

---

## Implemented Phases

All phases (2–11) are shipped in v0.1.0.

| Phase | What shipped |
|-------|-------------|
| **Phase 2** | WP-Admin & REST scaffolding. Encrypted credential store (`nvoos_saas_controller_credentials` option, AES-256-CBC keyed from `AUTH_KEY + SECURE_AUTH_KEY`). Deployment-config store (`nvoos_saas_controller_deployment` option, plaintext JSON). |
| **Phase 3** | Connection tester (`NVOOS_SaaS_Controller_Connection_Tester`) — read-only HTTPS preflights against Cloudflare (`/accounts/{id}`), Stripe (`/v1/account`), and OpenRouter (`/auth/key`). 10 s timeout. Never echoes secrets. |
| **Phase 4** | Read-only Cloudflare client (`NVOOS_SaaS_Controller_Cloudflare_Client`) covering D1 databases, KV namespaces, Workers scripts, and AI Gateway gateways. |
| **Phase 5a–5d** | Reconcile-plan generator (`NVOOS_SaaS_Controller_Plan_Generator`) — diffs desired config vs live Cloudflare state; emits `creates / updates / noops / orphans / errors` arrays with a `summary` count map. API failures go to `errors[]` and never throw, so a partial outage still produces a useful partial plan. Phase 5d adds the mutating Cloudflare client (`NVOOS_SaaS_Controller_Cloudflare_Mutating_Client`) and Worker multipart upload behind the HITL gate. |
| **Phase 5e** | Drift-manifest stamping (`scripts/stamp-drift-manifest.mjs`), auto-invoked by `npm run build:worker`. Writes `sha256`, `version`, and `built_at` to `worker/drift-manifest.json`. |
| **Phase 6** | Stripe client (`NVOOS_SaaS_Controller_Stripe_Client`) — idempotent create for products and prices; OpenRouter provisioning client (`NVOOS_SaaS_Controller_OpenRouter_Client`) — list + create runtime keys. Plan rows emitted for `stripe_product`, `stripe_price`, and `openrouter_key` kinds. |
| **Phase 7** | Stripe webhook verifier (`NVOOS_SaaS_Controller_Stripe_Webhook_Verifier`) — HMAC-SHA256, constant-time `hash_equals`, 300 s replay window, multi-`v1` rotation support. Webhook event store (`NVOOS_SaaS_Controller_Webhook_Event_Store`) — 200-entry ring buffer, idempotent by `provider` + `event_id`, no PII stored. |
| **Phase 8** | Background async Apply (`NVOOS_SaaS_Controller_Apply_Job`) — one-row-per-tick cron worker; state held in a 6 h transient; 200-row `MAX_TOTAL_ROWS` ceiling. |
| **Phase 9** | Background-apply admin UI — progress card (`<progress>` bar + counters + `last_message`), 2 s polling, Cancel button. Polling stops on terminal states `completed / cancelled / failed`. |
| **Phase 10** | Orphan cleanup — separate single-use HITL token (`nvoos_saas_orphan_` transient namespace), `POST /apply/orphans/preview` + `POST /apply/orphans/run`. Stripe rows are archived (`active=false`); everything else is permanently deleted. |
| **Phase 11** | Webhook Events card under the Operations tab — paginated table (event_id / type / ts / signature status / message), Refresh button, and Clear Events button. |

---

## REST API

**Namespace:** `/wp-json/nvoos-saas/v1/`

All routes require `manage_options` + REST nonce **except** `POST /webhooks/stripe`, which is signature-gated.

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/healthz` | Addon version + base-plugin liveness probe |
| `GET` | `/credentials` | Masked credential snapshot (plaintext never returned) |
| `POST` | `/credentials` | Set/update one or more credentials |
| `DELETE` | `/credentials` | Clear all credentials |
| `POST` | `/connections/test` | Live preflight against Cloudflare, Stripe, OpenRouter |
| `GET` | `/deployment` | Current desired topology config |
| `POST` | `/deployment` | Replace desired topology config |
| `POST` | `/plan` | Run reconcile-plan against live Cloudflare state |
| `GET` | `/audit-log` | Paginated audit-log entries (`?limit=&offset=`, newest first) |
| `DELETE` | `/audit-log` | Clear the audit log (records its own audit entry first) |
| `POST` | `/smoke-tests/run` | Execute the smoke-test sequence |
| `GET` | `/smoke-tests/last` | Most recent cached smoke-test result |
| `POST` | `/apply/preview` | Re-run plan + issue single-use HITL `apply_token` (409 if plan has errors) |
| `POST` | `/apply/run` | Consume `apply_token` and apply plan synchronously (410 if expired, 409 if used) |
| `POST` | `/apply/enqueue` | Consume `apply_token` and enqueue background apply job |
| `GET` | `/apply/jobs/{id}` | Poll background apply job progress |
| `POST` | `/apply/jobs/{id}/cancel` | Cancel a queued or running background job |
| `POST` | `/apply/orphans/preview` | Issue orphan-delete HITL token |
| `POST` | `/apply/orphans/run` | Consume orphan token and delete orphan resources |
| `POST` | `/drift/check` | Run a fresh drift check; always returns 200 with structured result |
| `GET` | `/drift/last` | Most recent cached drift-check result |
| `POST` | `/webhooks/stripe` | **Public, signature-gated.** Verifies `Stripe-Signature`, stores event, returns 200 fast on retries |
| `GET` | `/webhooks/events` | Paginated webhook event list (`?limit=&offset=`, newest first) |
| `DELETE` | `/webhooks/events` | Clear the webhook event store |

---

## Filters

| Filter | Default | Description |
|--------|---------|-------------|
| `nvoos_saas_controller_apply_token_ttl` | `900` (15 min) | TTL in seconds for single-use HITL apply tokens |
| `nvoos_saas_controller_audit_log_max_entries` | `200` | Maximum entries in the audit-log ring buffer |
| `nvoos_saas_controller_audit_log_record` | — | Return `false` to suppress a specific log entry before it is written |
| `nvoos_saas_controller_webhook_events_max_entries` | `200` | Maximum entries in the webhook event ring buffer |
| `nvoos_saas_controller_apply_job_state_ttl` | `21600` (6 h) | TTL in seconds for background apply job state transient |
| `nvoos_saas_controller_worker_dist_path` | `worker/dist/index.js` | Filesystem path of the Worker bundle to upload |
| `nvoos_saas_controller_worker_compatibility_date` | `2025-01-01` | Cloudflare Worker compatibility date sent on upload |
| `nvoos_saas_controller_worker_upload_metadata` | — | Merge additional fields into the Worker upload metadata |

---

## Data Storage

| Option key | Purpose |
|-----------|---------|
| `nvoos_saas_controller_credentials` | AES-256-CBC encrypted credentials blob |
| `nvoos_saas_controller_deployment` | Plaintext JSON desired Cloudflare topology |
| `nvoos_saas_controller_audit_log` | Audit-log ring buffer (last 200 entries) |
| `nvoos_saas_controller_webhook_events` | Webhook event ring buffer (last 200 entries) |
| `nvoos_saas_controller_last_drift_check` | Most recent drift-check result |
| `nvoos_saas_controller_last_smoke_test` | Most recent smoke-test result |
| `nvoos_saas_controller_deployed_worker` | Post-Apply Worker fingerprint `{ worker_name, sha256, etag, size, uploaded_at }` |

Transient keys follow the pattern `nvoos_saas_apply_{hash}` (apply tokens) and `nvoos_saas_orphan_{hash}` (orphan-delete tokens).

---

## Build

```bash
cd addons/saas-controller
npm ci
npm run build           # builds both Worker and Admin UI bundles
npm run build:worker    # esbuild → worker/dist/index.js  (stamps drift-manifest.json)
npm run build:admin     # @wordpress/scripts → assets/build/
npm run typecheck       # tsc --noEmit
npm run test            # jest via @wordpress/scripts
npm run check:drift-manifest  # CI check — verifies manifest is stamped without rewriting
```

The repo-wide `bin/build-addon-zips.sh` orchestrates this for releases and emits `build/nvoos-saas-controller-vX.Y.Z.zip`. `node_modules/` is never shipped; only `assets/build/` and `worker/dist/` are included.

---

## Related Documents

- [`addons/saas-controller/README.md`](../addons/saas-controller/README.md) — full implementation reference (class index, npm bucket table, build instructions)
- [`addons/cloud-worker/README.md`](../addons/cloud-worker/README.md) — the deployed Cloudflare Worker counterpart
- [`docs/SAAS_SETUP_GUIDE.md`](SAAS_SETUP_GUIDE.md) — end-user install/setup guide (prerequisites, account provisioning, connect tokens, billing, runbook)
- [`docs/features/nv-cloud.md`](features/nv-cloud.md) — NV oOS Cloud feature spec (plugin contract, hooks, constants)
- [`addons/saas-controller/THIRD_PARTY_NOTICES.md`](../addons/saas-controller/THIRD_PARTY_NOTICES.md) — bundled npm licenses
