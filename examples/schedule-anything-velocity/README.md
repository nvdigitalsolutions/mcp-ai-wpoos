# Schedule Anything — Velocity App

> **One-way mirror notice.** This repository is a one-way mirror of
> `examples/schedule-anything-velocity/` in the
> [`nvdigitalsolutions/mcp-ai-wpoos`](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
> monorepo. History is intentionally flat (one commit per sync,
> force-pushed). **Never commit here directly** — changes made in this repo
> are overwritten by the next sync. Open PRs against the monorepo instead.

Cloudways **Velocity** (managed Node.js: NGINX + PM2) deploy of the
**Schedule Anything** tenant SPA. The app is a zero-dependency static server
that also reverse-proxies the WordPress backend, so the SPA, the `wp_rest`
nonce handshake, and WordPress login all live on one origin — cookie auth
works cross-host with **no CORS configuration**.

**Caching contract** (same layer as the Complete Console): hashed
`/assets/*` → `public, max-age=31536000, immutable`; `index.html` →
`no-cache` + ETag (304 revalidation); unversioned root files (favicon) →
`max-age=86400` + ETag; gzip/brotli from an in-memory file cache;
default-deny proxy micro-cache for `GET` responses WordPress itself marks
`Cache-Control: public` (nonce/SSE `no-store` endpoints can never be
cached; `X-Cache-Status: HIT/MISS/BYPASS` for observability; knobs
`NVOOS_PROXY_CACHE=0` / `NVOOS_PROXY_CACHE_MAX_TTL`).

```
Browser ──> Velocity app (scripts/serve.mjs, PM2)
              ├── dist/                      # prebuilt SPA (no build step)
              ├── /wp-json, /wp-admin,       # proxied → NVOOS_TARGET_SITE
              │   /wp-login.php, /wp-includes
              └── (login page re-scoped to the app origin)
```

## Repository layout

```
.
├── server.js          # Velocity default entry (shim → scripts/serve.mjs)
├── package.json       # zero dependencies; no-op build + start shims
├── scripts/
│   └── serve.mjs      # the real server — static serving + reverse proxy
├── dist/              # prebuilt SPA bundle (git-tracked)
│   ├── index.html     # entry (served with Cache-Control: no-cache)
│   └── assets/        # hashed chunks (immutable, 1-year cache)
└── README.md          # this file
```

> `scripts/sync-dist.mjs` exists in the **monorepo** copy of this app
> (it copies the addon's build output into `dist/`); it is not needed at
> runtime and does not ship in this mirror.

## Backend requirements (the 3 plugins)

The `NVOOS_TARGET_SITE` WordPress site must run:

| Plugin | Notes |
|---|---|
| **NV oOS base** (`mcp-ai-wpoos`) | provides the core REST surface + auth plumbing |
| **NV oOS Pro** (`mcp-ai-wpoos-pro`) | schedules/presets/toolkits REST (`/mcp-ai-pro/v1`); install the **built distribution zip** — the repo-form addon has no plugin header by design |
| **Schedule Anything Platform** (`schedule-anything-platform`) | per-tenant toolkit entitlements + provisioning; WordPress **Multisite** for full tenancy, plus `SA_SAAS_API_KEY` in `wp-config.php` |

## Quick start (local)

```bash
git clone https://github.com/nvdigitalsolutions/nvoos-schedule-anything-velocity.git
cd nvoos-schedule-anything-velocity

# Point at any WordPress backend running the 3 plugins:
NVOOS_TARGET_SITE=http://localhost:8000 npm run serve
# → http://localhost:4180  (PORT env overrides)
```

Smoke checks:

```bash
curl -I localhost:4180/                          # 200, Cache-Control: no-cache
curl -I localhost:4180/assets/index-*.js         # 200, public, max-age=31536000, immutable
curl localhost:4180/wp-json/nvoos-saas/v1/auth/nonce   # proxied nonce handshake
```

Both `npm run serve` and `node server.js` (the Velocity entry) boot the same
server; `PORT` overrides the listen port (Velocity injects it from its
app-level `.env`).

## Velocity setup

1. Create a Node.js app and connect **this repository**.
2. **Root directory:** the repo root (default) — `package.json` sits at the
   top of this repo.
3. **Entry file:** leave Velocity's default — it executes `server.js` at
   the app root, which is a one-line shim that boots `scripts/serve.mjs`.
   (The shim exists because Velocity's default entry is `server.js`; without
   it PM2 fails with `Script not found` and the deploy ends
   `Status: FAILED (rc=1)`. If you prefer, set the entry to
   `scripts/serve.mjs` explicitly — both work. The real server carries the
   `#!/usr/bin/env node` shebang, required because Velocity executes the
   entry through the shell.)
4. **Build command:** none (a no-op `npm run build` shim is defined for
   Velocity auto-detection).
5. **Environment variables:** `NVOOS_TARGET_SITE=https://<your-wp-site>`
   (trailing slash tolerated). Without it the SPA loads but REST/auth calls
   have no backend.
6. Custom domain + SSL come from Velocity; `PORT` is injected automatically.

## Environment variables

| Variable | Required | Purpose |
|---|---|---|
| `NVOOS_TARGET_SITE` | yes (for cookie auth) | WordPress backend origin to proxy `/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes` |
| `PORT` | no | Listen port (Velocity injects it; local default 4180) |

## Caching & security

- **SPA caching contract:** `index.html` → `Cache-Control: no-cache`;
  hashed `/assets/*` → `public, max-age=31536000, immutable`.
- **Missing files 404 loudly:** only extensionless navigation paths fall
  back to `index.html`. A missing file-like path (e.g. a stale `index.html`
  referencing a rotated asset hash) returns 404 instead of the entry HTML —
  serving HTML for a `.css`/`.js` request trips browser strict-MIME refusal
  and masks a broken dist deploy.
- **Headers on static responses:** `X-Content-Type-Options: nosniff` +
  ramping HSTS (`max-age=86400` → 31536000 after a clean week). Proxied
  WordPress responses pass their own headers through untouched.
- **Proxy hardening:** hop-by-hop headers stripped; Set-Cookie `Domain`
  attributes dropped (host-only cookies for the app origin); `wp-login.php`
  form actions + `redirect_to` re-scoped so the login POST stays inside the
  proxy; upstream failure → `502` with `nvoos_bad_gateway` JSON.
- CSP is intentionally **not enforced** yet — the template in `serve.mjs` is
  Report-Only-ready.
- Never point `NVOOS_TARGET_SITE` at a host you don't control — the proxy
  forwards cookies for those prefixes.

## Multi-tenancy

Tenant identity comes from the subdomain (`TenantContext`). Production
multi-tenant routing (subdomain → tenant site) is the platform's
Cloudflare Worker edge job; **this Velocity app serves one WordPress network
per deployment**. On the main domain the tier falls back to `unknown` and
the public booking portal works from `/book/:tenant` regardless. WordPress
CORS configuration is **not needed** in this proxy mode (all requests are
same-origin).

## Sync & deployment plumbing

- CI workflow `sync-nvoos-schedule-anything-velocity.yml` (in the monorepo)
  assembles this tree at the repo root, smoke-tests it (boots `serve.mjs`,
  curls the static branch), and force-pushes one flat commit per sync.
- It runs on every push touching `examples/schedule-anything-velocity/**`
  on `main`/`alpha-working`, plus `workflow_dispatch`.
- After the monorepo's SPA changes merge, the monorepo-side
  `npm run sync:dist` refreshes `dist/` and the next sync deploys it.

## License

The Schedule Anything SPA bundle and this app are proprietary
(`UNLICENSED` — see `package.json`). The WordPress backend stack is
separately licensed per plugin: GPLv3-or-later (base) and proprietary
(Pro, Schedule Anything Platform).
