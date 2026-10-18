# Schedule Anything — Cloudways Velocity App

Prebuilt-bundle deploy of the Schedule Anything tenant SPA for **Cloudways
Velocity** (managed Node.js: NGINX + PM2). The app is a zero-dependency static
server that also reverse-proxies the WordPress backend, so the SPA, the
`wp_rest` nonce handshake, and WordPress login all live on one origin — cookie
auth works cross-host without any CORS configuration.

```
Browser ──> Velocity app (serve.mjs)
              ├── dist/                      # built SPA (from addons/schedule-anything-spa)
              ├── /wp-json, /wp-admin,       # proxied → NVOOS_TARGET_SITE
              │   /wp-login.php, /wp-includes
              └── (login page re-scoped to the app origin)
```

## Backend requirements (the 3 plugins)

The `NVOOS_TARGET_SITE` WordPress site must run:

1. **NV oOS base** (`mcp-ai-wpoos`)
2. **NV oOS Pro** (`mcp-ai-wpoos-pro`) — install the **built distribution
   zip** (`build/mcp-ai-wpoos-pro-*.zip`); the repo-form addon folder has no
   plugin header by design
3. **Schedule Anything Platform** (`schedule-anything-platform`) — WordPress
   Multisite for full tenancy, plus `SA_SAAS_API_KEY` in `wp-config.php`

## Velocity setup

1. Create a Node.js app; connect it to the mirror repo
   `nvdigitalsolutions/nvoos-schedule-anything-velocity` (see "Deployment
   plumbing" below).
2. **Root directory:** the repo root (default) — this repo contains only the
   app, so no root-directory override is needed. Keep it in mind only if
   Velocity ever asks: the `package.json` must sit at the root.
3. **Entry file:** `scripts/serve.mjs` (carries the `#!/usr/bin/env node`
   shebang — without it the sh shell parses the ESM and PM2 restart-loops).
4. **Build command:** none (a no-op `npm run build` shim is defined for
   Velocity auto-detection).
5. **Runtime env:** `NVOOS_TARGET_SITE=https://<your-wp-site>` (trailing
   slash tolerated). Without it the SPA loads but REST/auth calls have no
   backend.
6. Custom domain + SSL come from Velocity; `PORT` is injected automatically.

## Deployment plumbing (mirror sync)

Velocity deploys from a **git repo**, and the monorepo cannot serve that
role directly (no root `package.json` → Velocity build dies on `npm enoent`,
plus it would clone the entire monorepo). So this app is mirrored to a
dedicated repo by CI — the same pattern the standalone Pro SPA uses
(`sync-nvoos-pro-spa-standalone.yml` → `nvoos-pro-spa-standalone`):

- `.github/workflows/sync-nvoos-schedule-anything-velocity.yml` assembles the
  app tree **at the mirror repo root** (this mirror ships only the app, so
  Velocity's root directory is simply the repo root — unlike the Pro SPA
  mirror, which also ships vendored sources under `addons/` and must nest
  its app under `examples/`), smoke-tests it (boots `serve.mjs`, curls the
  static branch), and force-pushes one flat commit per sync to
  `nvdigitalsolutions/nvoos-schedule-anything-velocity`.
- It runs on every push touching `examples/schedule-anything-velocity/**` on
  main/alpha-working, plus `workflow_dispatch`.

**One-time activation (owner steps):** create the private mirror repo, add a
repo-scoped deploy token as the repository secret
`NVOOS_SCHEDULE_ANYTHING_VELOCITY_REPO_TOKEN`, run the workflow once via
workflow_dispatch to backfill, then connect the mirror repo in Velocity
(root directory = repo root, entry `scripts/serve.mjs`).

## Keeping dist/ fresh

The SPA lives and is built in `addons/schedule-anything-spa`. After any SPA
change:

```bash
npm run sync:dist        # in examples/schedule-anything-velocity
# or: npm run sync:dist:build  (rebuilds the addon first)
git add dist && git commit
```

`dist/` is intentionally git-tracked (cleared + copied on each sync so
rotated chunk hashes never linger).

## Caching & security

- `index.html` → `Cache-Control: no-cache`; hashed `/assets/*` →
  `public, max-age=31536000, immutable` (industry SPA contract — the entry
  is never cached, bundles are).
- `X-Content-Type-Options: nosniff` + a ramping HSTS header on static
  responses only; proxied WordPress responses pass their own headers
  through untouched. CSP is deliberately Report-Only-templated in
  `serve.mjs` (not enforced yet).
- The proxy strips hop-by-hop headers, drops Set-Cookie `Domain` attributes
  (host-only cookies for the app origin), and re-scopes `wp-login.php`
  form actions + `redirect_to` so the login POST stays inside the proxy.
- Never point `NVOOS_TARGET_SITE` at a host you don't control — the proxy
  forwards cookies for those prefixes.

## Multi-tenancy note

Tenant identity comes from the subdomain (`TenantContext`). Production
multi-tenant routing (subdomain → tenant site) is the plan's Cloudflare
Worker/edge job (D2); this Velocity app serves one WordPress network per
deployment. On the main domain the tier falls back to `unknown` and the
booking portal works from `/book/:tenant` regardless. CORS configuration on
the WordPress side is **not needed** in this proxy mode (all requests are
same-origin); it only matters if the SPA later calls the REST API directly
(see Proposal 081 §9 for the origin + route-prefix settings).

## Local verification

```bash
NVOOS_TARGET_SITE=http://localhost:8099 npm run serve   # any WP sandbox works
curl -I localhost:4180/                                  # 200, no-cache
curl -I localhost:4180/assets/index-BKB4-r7J.js          # 200, immutable
curl localhost:4180/wp-json/nvoos-saas/v1/auth/nonce     # proxied nonce handshake
```
