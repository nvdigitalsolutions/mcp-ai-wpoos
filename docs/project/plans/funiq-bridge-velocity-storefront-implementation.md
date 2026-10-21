# Funiq Bridge Velocity Storefront — Comprehensive Implementation Plan

> **Status:** Implementation Plan (companion to Proposal 085) · **Target:** Phase 1–5 MVP in ~3 focused PRs + 1 private-repo scaffold · **Platform:** Cloudways Velocity (Node static + BFF proxy) + WordPress running `addons/funiq-bridge`
>
> **Last Updated:** 2026-10-21
> **Author:** NV Digital Solutions
>
> **Based on:** Proposal 085 (industry research: Envato Market licensing, Auth0/Duende BFF same-origin session pattern, headless-ecommerce SEO constraints, licensed-asset CI hygiene), the two proven Velocity apps (`examples/schedule-anything-velocity`, `examples/nvoos-complete-velocity`), Proposal 072 (proprietary marking), and PR #7166 (console client/hook/test patterns for the deferred admin track).

---

## Table of Contents

1. [Architecture Decisions](#1-architecture-decisions)
2. [Repository Structure](#2-repository-structure)
3. [Phase-by-Phase Build Plan](#3-phase-by-phase-build-plan)
4. [Testing Strategy](#4-testing-strategy)
5. [Deploy & Sync Runbook](#5-deploy--sync-runbook)
6. [Verification Matrix](#6-verification-matrix)
7. [Follow-on Work (deferred)](#7-follow-on-work-deferred)
8. [Phase B — Laravel Standalone Port (gated placeholder)](#8-phase-b--laravel-standalone-port-gated-placeholder)

---

## 1. Architecture Decisions

| # | Decision | Alternatives Considered | Rationale | Industry Source |
|---|---|---|---|---|
| D1 | Single Velocity app serving the storefront PWA at root + proxying WordPress (admin stays in wp-admin) | Admin-only app; standalone admin SPA on the app origin | Storefront is the product surface; proxied `/wp-admin` gives the Funiq CMS a home on the same origin at zero build cost; standalone admin deferred | Auth0 [BFF](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/); Proposal 083 §2.3 |
| D2 | Licensed PWA bundle confined to two **private** repos (`nvoos-funiq-pwa` source, `nvoos-funiq-velocity` deploy mirror); NV-authored files stay public | All-public mirror; fully private primary repo | Envato Regular License prohibits redistribution (even modified); public git history is immutable → the boundary must be structural | [Envato Market license FAQ](https://help.market.envato.com/hc/en-us/sections/200616950-Envato-Market-License-FAQ) |
| D3 | Monorepo sync workflow assembles the tree and **injects the PWA `dist/` at CI time** via a token-gated clone of the private PWA repo | Commit the bundle anywhere public; build on Velocity | Public repo never stores licensed bytes; Velocity still needs zero build step; scoped deploy tokens are the standard private-artifact mechanism | r/devops [dependent-repo CI/CD](https://www.reddit.com/r/devops/comments/18eo4g5/how_do_you_handle_cicd_for_multiple_repos_that/); repo precedent: SA sync workflow |
| D4 | `serve.mjs` ported from the SA app with one added rule: `/api/*` → `/wp-json/funiq/v1/*` | `.htaccess` rewrite on the WP host; CORS + config edit in the PWA | Replaces the bridge README's Option A at the proxy layer — zero PWA changes, no CORS, one origin | Bridge README "Connecting the Funiq PWA frontend"; Duende [BFF](https://docs.duendesoftware.com/bff/) |
| D5 | Storefront ships **CSR as-is** in v1; SSR/prerender + standalone admin are private-repo follow-ons | SSR wrapper in v1 | Modifying licensed code for SSR is permitted but large; v1 optimizes for a working deploy, SEO limitation documented | AskPhill [headless commerce](https://askphill.com/blogs/blog/shopify-headless) |
| D6 | Sync triggers on `examples/funiq-bridge-velocity/**` only; PWA-side deploys originate in the private repo (dispatch/manual) | Trigger on `addons/funiq-bridge/**` too | The v1 app embeds no bridge sources (admin stays in wp-admin); coupling the PWA repo's release cadence to monorepo pushes would need a second workflow in the private repo | Repo precedent: complete-console sync path filters |
| D7 | Private mirror repo keeps the SA flat-history force-push model | Normal history in the deploy repo | Identical deploy mechanics to both existing Velocity apps; iteration on licensed code happens in `nvoos-funiq-pwa`, only artifacts flow through the mirror | Repo precedent: SA/console mirrors |

### 1.2 Technology Stack

| Layer | Technology | Purpose |
|---|---|---|
| **Storefront** | Funiq React PWA (purchased, unmodified in v1) | Catalog/browse surface against `/api/*` |
| **Admin** | Funiq CMS SPA inside wp-admin (existing `addons/funiq-bridge` build) | Product/promo/global management |
| **Server** | Zero-dependency Node (`server.js` shim → `scripts/serve.mjs`) on Velocity/PM2 | Static + reverse proxy incl. `/api` rule |
| **Backend** | WordPress + `addons/funiq-bridge` (standalone; base plugin optional) | REST surface `/wp-json/funiq/v1/*` (Payload-shaped) |
| **Sync** | GitHub Actions → private mirror `nvoos-funiq-velocity` (force-push, flat history) + token-gated clone of `nvoos-funiq-pwa` | Velocity redeploys from the mirror |

---

## 2. Repository Structure

```
Public monorepo — examples/funiq-bridge-velocity/   (NV-authored only)
├── package.json            # type:module, main:server.js, "license": "UNLICENSED", no-op build/start shims
├── server.js               # ESM shim: import('./scripts/serve.mjs')  [SA pattern, root entry for PM2]
├── LICENSE                 # proprietary text, consistent with addons/funiq-bridge/LICENSE
├── THIRD_PARTY_NOTICES.md  # esbuild/dev-tooling + runtime-provided WP/React packages
├── README.md               # mirror notice + storefront/admin usage + Envato boundary policy
├── scripts/
│   ├── serve.mjs           # SA proxy core ported + /api/* → /wp-json/funiq/v1/* rule
│   └── sync-dist.mjs       # dev-only: copies the PWA build out of a local checkout (never ships)
└── (NO dist/ in git)       # dist/ exists only in the private mirror, injected at CI time

Private repo — nvdigitalsolutions/nvoos-funiq-pwa   (owner-managed)
├── source of the purchased PWA (or the item zip + build notes)
├── package.json / build scripts
└── LICENSE.txt             # Envato purchase metadata (proof of license, never public)

Private mirror — nvdigitalsolutions/nvoos-funiq-velocity   (flat, force-push only)
├── server.js / package.json / scripts/serve.mjs          # from the monorepo
└── dist/                    # storefront PWA bundle (licensed) — injected by the sync workflow
```

**Boundary rule (enforced in review):** no file under
`examples/funiq-bridge-velocity/` in the public monorepo may contain or
reference the PWA's code or assets by value — only the `/api` proxy rule,
README pointers, and workflow targets.

---

## 3. Phase-by-Phase Build Plan

### Phase 0 — Decision gates (no code)

- [ ] Owner resolves Proposal 085 §7 Q1 (Regular vs Extended License) and Q2–Q4.
- [ ] Verify the existing `addons/funiq-bridge/src` admin SPA contains no
      vendored code copied from the purchased PWA (audit pass, recorded in
      the PR body). The bridge stays in the public monorepo only if clean.

### Phase 1 — Private repo scaffolding (owner-side, one-time)

1. Create **`nvoos-funiq-pwa`** (private): commit the purchased PWA source
   or the item zip + a build script producing a static `dist/`, plus
   `LICENSE.txt` holding the Envato purchase metadata.
2. Create **`nvoos-funiq-velocity`** (private): empty repo; the workflow
   backfills it. Add repo-scoped deploy tokens as secrets:
   - `NVOOS_FUNIQ_VELOCITY_REPO_TOKEN` (monorepo, 31 chars — under the
     40-char GitHub secret limit that bit the SA track)
   - `NVOOS_FUNIQ_PWA_READ_TOKEN` (monorepo, read-only clone of the PWA repo)
3. Confirm Velocity can see both private repos (owner GitHub OAuth).

### Phase 2 — Monorepo example tree (PR 1, public)

1. Copy the SA app skeleton into `examples/funiq-bridge-velocity/`:
   `server.js`, `package.json` (name `funiq-bridge-velocity`,
   `"license": "UNLICENSED"`, no deps), `LICENSE`, `THIRD_PARTY_NOTICES.md`,
   `README.md` (one-way mirror notice + Envato boundary policy).
2. Port `scripts/serve.mjs` from
   `examples/schedule-anything-velocity/scripts/serve.mjs` with the single
   documented delta: the `/api/*` → `/wp-json/funiq/v1/*` proxy rule
   (ordered before the generic `/wp-json` proxying; preserves query
   strings; passes through the same cookie/hop-by-hop handling).
3. Add `scripts/sync-dist.mjs` (dev-only): copies a local PWA build into
   `dist/` for local smoke runs; excluded from the mirror tree like the SA
   app's equivalent.
4. Smoke-locally: `NVOOS_TARGET_SITE=<local WP> node server.js`, curl `/`,
   `/api/products`, `/wp-json/funiq/v1/products`.

### Phase 3 — Bridge plugin hardening seed (PR 2, public)

1. Add `GET /funiq/v1/auth/nonce` to the bridge (`includes/REST/`):
   public route returning `{ nonce, user }` using the WP 6.9 cookie-rebind
   pattern (validate auth cookie → re-bind user → `wp_create_nonce('wp_rest')`)
   + `Cache-Control: no-cache, no-store, must-revalidate`. Seeds the future
   standalone admin and any authenticated PWA flows; unused by v1's
   proxied-wp-admin path.
2. Regression test in `tests/test-funiq-bridge-nonce.php` mirroring the SA
   nonce test.
3. Confirm the public `GET` collection routes answer correctly through the
   proxy (Payload paginated shape) — no auth changes.

### Phase 4 — Sync workflow with licensed-bundle injection (PR 3, public)

1. Add `.github/workflows/sync-nvoos-funiq-velocity.yml` modeled on the SA
   workflow:
   - Triggers: push to `main`/`alpha-working` touching
     `examples/funiq-bridge-velocity/**`, plus `workflow_dispatch`.
   - Steps: checkout monorepo → assemble the NV-authored tree to a temp
     dir → clone `nvoos-funiq-pwa` (token) and copy its built `dist/` in →
     strip `sync-dist.mjs` → port the SA smoke gates (syntax checks,
     `dist/index.html` present, asset-closure scan, boot `node server.js`,
     curl `/`, an `/api` route, and the `text/css` MIME gate) → `git init -b
     main` → force-push to `nvoos-funiq-velocity` with the token.
   - Guard: a job step asserts the mirror target is **private** (fail the
     sync if it is not) — the license boundary enforced by CI.
2. Run once via dispatch to backfill the mirror; verify the private repo
   tree matches the assembled temp tree exactly.

### Phase 5 — Velocity deploy runbook

1. In Velocity: create a Node app connected to **`nvoos-funiq-velocity`**
   (private), branch `main`, root directory = repo root, entry `server.js`
   (or `scripts/serve.mjs`; the shim makes either work — shebang mandatory),
   no build command, env `NVOOS_TARGET_SITE=https://<bridge-enabled WP site>`.
2. Attach the custom domain + SSL (storefront at root; wp-admin reachable
   at `/wp-admin` on the same origin).
3. Verify: storefront boots from `/`; `/api/products` returns the Payload
   envelope; `/wp-admin` login works through the proxy; Funiq CMS renders
   after login; uploads via the WP media modal still work (proxied).

### Phase 6 — Release bookkeeping

- [ ] `CHANGELOG.md` entry (Funiq Velocity storefront).
- [ ] Root `CREDITS.md` + example `THIRD_PARTY_NOTICES.md`.
- [ ] `mcp-ai-schedule-anything-ops` skill §6 gains a "Funiq storefront"
      subsection documenting the private-mirror variant (the first
      licensed-content Velocity app — a new pattern in the family).

---

## 4. Testing Strategy

| Layer | What | Where |
|---|---|---|
| Workflow smoke gates | Syntax, asset closure, boot, `/`, `/api/*`, MIME, private-target assertion | `sync-nvoos-funiq-velocity.yml` |
| Proxy unit behavior | `/api` rule ordering + query passthrough | Local smoke script in Phase 2 (extend later) |
| Nonce endpoint | Cookie-rebind regression (public route, user-0 trap) | `tests/test-funiq-bridge-nonce.php` |
| Bridge REST regression | Existing funiq REST tests still green after Phase 3 | root PHPUnit `--group=funiq-bridge` |
| License boundary | Review checklist: no PWA bytes in the public PR diff | PR review + workflow target assertion |

---

## 5. Deploy & Sync Runbook

- **Storefront change** → build in `nvoos-funiq-pwa` → run the sync
  workflow via `workflow_dispatch` (or a future private-repo workflow) →
  mirror updates → Velocity redeploys.
- **Server/proxy change** → PR in the monorepo touching
  `examples/funiq-bridge-velocity/**` → merge triggers the sync → mirror →
  Velocity.
- **Bridge plugin change** → normal plugin deploy to
  `NVOOS_TARGET_SITE`; **mind the SPA-ahead-of-the-bundle class**: the
  storefront PWA must never call a route the deployed bridge lacks.
- **Rollback:** redeploy the previous mirror commit (flat history makes
  this a one-click redeploy in Velocity).

---

## 6. Verification Matrix

| Check | Command / evidence |
|---|---|
| Mirror is private | Repo settings + workflow assertion step (Phase 4) |
| No licensed bytes in the public repo | `git grep` for PWA markers across `examples/funiq-bridge-velocity/` → 0 hits |
| Storefront serves from the app origin | `curl -I https://<app>/` → 200, `Cache-Control: no-cache` |
| `/api` proxy works | `curl https://<app>/api/products?limit=10` → `{"docs":[...],"totalDocs":…}` |
| Admin reachable same-origin | Login at `/wp-login.php` → `/wp-admin` → Funiq CMS loads |
| Nonce endpoint (deferred-use seed) | `curl https://<app>/wp-json/funiq/v1/auth/nonce` → `{nonce,user}` with cookie jar |

---

## 7. Follow-on Work (deferred)

1. **Standalone Funiq CMS admin** (`/admin` on the app origin): second
   esbuild output (bundled React, no WP externals), PR 7166-style
   `FuniqClient`/hooks/vitest, REST media uploads replacing the `wp.media`
   modal, consuming the Phase-3 nonce endpoint. *(Note: in the Laravel
   standalone world this admin is served by Laravel — see §8.)*
2. **SSR/prerender track** (private repo) for storefront SEO per
   Proposal 085 §2.3.
3. **Authenticated storefront flows** (accounts/cart/checkout) — scoped
   separately; the bridge's REST does not currently emulate Payload's
   `/api/users` JWT model.

---

## 8. Phase B — Laravel Standalone Port (gated placeholder)

> **Placeholder** — full analysis lives in
> [`docs/project/proposals/085-funiq-laravel-standalone-integration-appendix.md`](../proposals/085-funiq-laravel-standalone-integration-appendix.md).
> This section exists so the track is **tracked, not started**. No Phase B
> work begins until its gates are cleared.

### 8.1 Entry gates (all required)

- [ ] Proposal 085 Phase 0–6 v1 is shipped and verified (WP-backend Velocity
      storefront live).
- [ ] Owner confirms **Laravel over FastAPI** (the Python alternative is a
      live open option — appendix §6).
- [ ] **Laravel adapter spike** completed in dev: `nvoos/core` +
      `nvoos/laravel-adapter` on Octane (FrankenPHP) with one real tool
      executed end-to-end — the repo's stated v1.4.0 spike, de-risking the
      adapter's missing production track record.
- [ ] Golden-contract suite captured: the bridge REST responses frozen as
      fixtures (the PWA-facing Payload envelope is the migration invariant).

### 8.2 Scope (T1 — backend flip behind the existing BFF)

| Item | Notes |
|---|---|
| Laravel funiq service scaffold | Eloquent models + migrations (products/promotions/promocodes; category/brand/color/status), API Resources, Payload pagination trait ported from `BaseController` |
| Route flip — public `GET`s first | `serve.mjs` `/api/*` target moves to the Laravel origin; WP stays reachable as instant rollback |
| Auth equivalent | Laravel session cookie through the same origin; `manage_funiq` → Gates/Policies; login re-scoping in `serve.mjs` generalized beyond `wp-login.php` |
| Data ETL | One-time WP → PostgreSQL/MySQL with typing (untyped post meta → `decimal`/`jsonb`/`boolean`); record-level contract diffing during the run |
| Write-flip + admin relocation | Deferred until the standalone admin (§7.1) moves off `wp.media` — then `POST`/`PUT`/`DELETE` flip and the bridge plugin retires |

### 8.3 NV oOS merge (Phase C — after T1 is stable)

- [ ] `composer require nvoos/core nvoos/laravel-adapter` + service-provider
      binding of the 8 adapters.
- [ ] Funiq `ContentStoreInterface` implementation over the Eloquent models
      (catalog becomes an oOS data source; AI tools read/write products).
- [ ] pgvector semantic search, Horizon background jobs, Reverb streaming
      (chat/admin assistant) — per `laravel-scale-deployment-architecture.md`.

### 8.4 Do-not-start rules

- No Phase B PRs in the public monorepo until every §8.1 gate is checked.
- The purchased PWA bundle stays confined to the private repos in **every**
  Phase B topology (Envato boundary is backend-agnostic).
- `addons/funiq-bridge` is not modified for the port — the Laravel service
  is built alongside (Strangler Fig); the bridge is retired only at the
  write-flip stage.
