# Funiq PWA — Velocity App (Proposal 085)

Cloudways **Velocity** (managed Node.js: NGINX + PM2) deploy of the licensed
Funiq PWA storefront, kept as the **A/B rollback leg** for the NV-authored
Astro storefront (Proposal 092; owner decision Q1: keep A/B until parity
review, then retire).

> **One-way mirror notice.** The deployed repo
> `nvdigitalsolutions/nvoos-funiq-velocity` is a one-way mirror assembled by
> the sync workflow in the monorepo. History is intentionally flat (one
> commit per sync, force-pushed). **Never commit to the mirror directly** —
> changes made there are overwritten by the next sync. Open PRs against the
> monorepo instead.
>
> **License boundary (Envato).** The PWA bundle under `dist/` is a
> PURCHASED, Envato-licensed item. It lives only in the **private** repos
> (`nvoos-funiq-pwa` source, `nvoos-funiq-velocity` mirror) and is injected
> into the mirror at CI time. **No PWA bytes ever enter the public
> monorepo** — this example ships only the NV-authored server/proxy files,
> and the sync workflow fails if the mirror target is not private.

## Architecture

```
Browser ──> Velocity app (scripts/serve.mjs, PM2)
              ├── dist/                      # purchased PWA bundle   ← licensed
              ├── /api/*                     # proxied → /wp-json/funiq/v1/*
              ├── /wp-json, /wp-admin,       # proxied → NVOOS_TARGET_SITE
              │   /wp-login.php,             #   (Funiq CMS stays inside
              │   /wp-includes               #    wp-admin — same origin)
              └── (login page re-scoped to the app origin)
```

The zero-dependency server also reverse-proxies the WordPress backend, so
the SPA, the bridge REST envelope and WordPress login all live on one
origin — cookie auth works cross-host with **no CORS configuration**.

## Proxy routes & hardening

| Route | Behaviour |
|---|---|
| `/api/*` | → `/wp-json/funiq/v1/*` (bridge REST, Payload-shaped envelope) — ordered **before** the generic `/wp-json` proxy |
| `/wp-json/*`, `/wp-includes/*` | proxied verbatim |
| `/wp-admin/*`, `/wp-login.php` | proxied + **admin hardening**: `Location` rewritten to the request origin, `X-Robots-Tag: noindex`, never micro-cached |
| everything else | static from `dist/` with SPA fallback |

## Caching contract

Hashed `/assets/*` → `public, max-age=31536000, immutable`; `index.html` →
`no-cache` + ETag (304 revalidation); unversioned root files →
`max-age=86400` + ETag; gzip/brotli from an in-memory file cache;
default-deny proxy micro-cache for `GET` responses WordPress itself marks
`Cache-Control: public` (nonce/SSE `no-store` endpoints can never be
cached; `X-Cache-Status: HIT/MISS/BYPASS` for observability; knobs
`NVOOS_PROXY_CACHE=0` / `NVOOS_PROXY_CACHE_MAX_TTL`).

## Local smoke

```bash
# Sync a local PWA build out of the private repo (dev-only):
FUNIQ_PWA_DIST=/path/to/nvoos-funiq-pwa/dist npm run sync:dist
# Serve + proxy (example backend):
NVOOS_TARGET_SITE=https://your-bridge-site npm run serve
```

## Owner activation (one-time — Proposal 085 Phase 1 / Phase 5)

1. Create **`nvoos-funiq-pwa`** (private): commit the purchased PWA source +
   build script producing `dist/`, plus `LICENSE.txt` with the Envato
   purchase metadata.
2. Create **`nvoos-funiq-velocity`** (private, empty): the sync workflow
   backfills it.
3. Add repo-scoped deploy tokens as monorepo secrets:
   `NVOOS_FUNIQ_PWA_READ_TOKEN` (read-only clone of the PWA repo),
   `NVOOS_FUNIQ_VELOCITY_REPO_TOKEN` (push to the mirror). The workflow
   skips with a notice until both exist.
4. In Velocity: connect the mirror, root directory = repo root, entry
   `server.js`, no build command, env `NVOOS_TARGET_SITE=<bridge-enabled
   WP site>`.
5. Run the sync workflow once via `workflow_dispatch` to backfill.

## Rollback (A/B coexistence)

Both Velocity apps (this PWA and `nvoos-astro-velocity`) point at the same
backend. Split traffic with Velocity web rules (`app_web_rules_update`,
type `redirect`): `temporary` redirect on `src: /` with condition
`cookie ~ nvoos_front=astro` → the Astro origin; the inverse rule here for
`nvoos_front=pwa`. The shared backend means rolling back never needs a data
migration — flip the rule and redeploy the previous mirror commit.
