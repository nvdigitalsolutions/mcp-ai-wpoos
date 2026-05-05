# NV oOS SaaS Controller Addon

**Operator-side toolkit to deploy and manage the NV oOS Cloud control plane** (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter) from inside WP-Admin.

This addon is the operator-side counterpart to `addons/cloud-worker/`. Where `cloud-worker` is the deployed runtime, the **SaaS Controller** is the WordPress plugin that lets a maintainer **provision, plan/apply changes to, drift-check, and audit** that runtime — without leaving WP-Admin.

> **Status:** v0.1.0 — Phases 2, 3, & 4 landed (WP-Admin & REST plumbing + interactive credentials wizard with live preflight + read-only Reconcile-Plan generator). Subsequent PRs will land the Apply step (HITL-gated), drift banner, audit-log viewer, and smoke tests.

## What's available today (Phases 2 / 3 / 4)

- **Top-level admin menu** — `WP-Admin → NV oOS SaaS` (capability: `manage_options`) with three tabs:
  - **Overview** — interactive React **Credentials Wizard** (Credentials → Validate → Save) plus a static masked-credentials table fallback for no-JS environments.
  - **Deployment** — desired Cloudflare topology editor (Worker name, account ID override, AI Gateway slug, D1 databases, KV namespaces) plus a **Run Plan** button that calls `POST /nvoos-saas/v1/plan` and renders the structured plan in-place. Read-only — no mutation occurs on this tab.
  - **Packages** — in-product credits surface listing every bundled npm dependency with upstream homepage, license, and copyright.
- **Encrypted credential store** (`nvoos_saas_controller_credentials` option) — AES-256-CBC at rest, derived from `AUTH_KEY + SECURE_AUTH_KEY`. Allowed keys: `cloudflare_account_id`, `cloudflare_api_token`, `stripe_secret_key`, `stripe_webhook_secret`, `openrouter_api_key`.
- **Deployment-config store** (`nvoos_saas_controller_deployment` option) — desired Cloudflare topology, persisted as plaintext JSON (no secrets). Per-field sanitisation enforces Worker-name slug rules and Workers binding identifier rules (`[A-Z][A-Z0-9_]*`).
- **Connection tester** (`NVOOS_SaaS_Controller_Connection_Tester`) — performs read-only HTTPS preflights against Cloudflare (`/accounts/{id}`), Stripe (`/v1/account`), and OpenRouter (`/auth/key`). 10 s per-request timeout, normalised `{ ok, latency_ms, status, message }` shape, never echoes secrets.
- **Cloudflare client** (`NVOOS_SaaS_Controller_Cloudflare_Client`) — read-only wrapper around `GET /accounts/{id}/d1/database`, `…/storage/kv/namespaces`, `…/workers/scripts`, `…/ai-gateway/gateways`. No mutation methods exist on this client; the Phase 5 Apply step will use a separate mutating client behind the HITL gate.
- **Plan generator** (`NVOOS_SaaS_Controller_Plan_Generator`) — diffs the desired config against live Cloudflare state. Output is a structured plan with `creates` / `updates` / `noops` / `orphans` / `errors` arrays plus a `summary` count map. Cloudflare API failures are recorded in `errors[]` (never thrown), so a partial network outage on one section still produces a useful plan for the rest.
- **REST namespace** `/wp-json/nvoos-saas/v1/` (every route requires `manage_options` + REST nonce):
  - `GET    /healthz` — addon version + base-plugin liveness probe.
  - `GET    /credentials` — masked snapshot (never returns plaintext).
  - `POST   /credentials` — set/update one or more credentials.
  - `DELETE /credentials` — clear all credentials.
  - `POST   /connections/test` — run live preflight against the three providers (uses supplied values or falls back to stored).
  - `GET    /deployment` — current desired config.
  - `POST   /deployment` — replace desired config (JSON body).
  - `POST   /plan` — run the reconcile-plan generator against live Cloudflare state.

## Features (planned)

- **One-Click Wizard** — collect Cloudflare/Stripe/OpenRouter credentials, validate them, and provision D1 + KV + Worker bindings.
- **Plan / Apply** — terraform-style preview of every reconcile action before it runs (D1 schema diffs, secret rotations, Worker updates).
- **Drift Detector** — periodic check of the deployed Worker vs. the addon's pinned `dist/index.js` checksum.
- **Audit Log** — every Cloudflare/Stripe call captured with operator + result.
- **Smoke Tests** — one-click "is the SaaS reachable?" preflight.

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

GPL-3.0-or-later. See the repository root `LICENSE`.
