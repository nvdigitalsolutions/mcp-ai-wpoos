# nvoos-astro-velocity — Velocity Deploy Setup

> Companion to `examples/nvoos-astro-velocity/README.md` (Proposal 092 plan,
> deploy runbook §9). The app deploys to Cloudways **Velocity** (managed
> Node.js: NGINX + PM2) using the connected-repo mirror pattern established
> by Proposal 085 §3.3.

## Repos

| Repo | Visibility | Role |
|---|---|---|
| `nvdigitalsolutions/mcp-ai-wpoos` (`examples/nvoos-astro-velocity/`) | Public | Source of truth (MIT). Build, tests, and mirror assembly run here. |
| `nvdigitalsolutions/nvoos-astro-velocity` | **Public** (owner decision Q6) | Deploy mirror — prebuilt `dist/` + BFF + runtime `package.json`. Flat history, force-pushed by `sync-nvoos-astro-velocity.yml`. **Never commit here directly.** |

## One-time activation

1. Create the public mirror repo `nvdigitalsolutions/nvoos-astro-velocity`.
2. Add a repo-scoped deploy token as the monorepo secret
   `NVOOS_ASTRO_VELOCITY_REPO_TOKEN`.
3. Run the sync workflow once via `workflow_dispatch` (backfills the mirror).
4. In Velocity: create a Node.js app, connect the mirror repo.

## Velocity app settings

| Setting | Value |
|---|---|
| Root directory | repo root (`package.json` at the top) |
| Entry file | default — `server.js` (one-line shim → `scripts/serve.mjs`; PM2 requires the root entry and the shebang) |
| Build command | none (a no-op `npm run build` shim ships as the second line of defence) |
| Node version | 22 LTS (Astro 7 engines: ≥ 20.3.0) |

> **Unlike the console mirrors:** the Astro SSR runtime needs its
> dependencies, so the mirror keeps a real `package.json` and Velocity runs
> its normal `npm install`. Only `dist/` is prebuilt.

## Environment variables (app-level `.env` in Velocity)

| Variable | Required | Purpose |
|---|---|---|
| `NVOOS_TARGET_SITE` | yes | Backend origin for the `/api/*` (→ `nvoos-commerce/v1`) and `/media/*` proxies. No backend admin is proxied — the admin is the standalone Astro CMS on `/manage` |
| `ASTRO_SITE` | no (runtime) | **Bake-time only** — the mirror rebuild sets it (default `https://astro.nvoos.cloud`, repo variable `ASTRO_SITE` / dispatch `client_payload.astro_site`). A runtime value does nothing. |
| `NVOOS_CURRENCY` | no | Price formatting + JSON-LD (default `USD`) |
| `PORT` | no | **Injected by Velocity** — do not set manually. A committed/manual `.env` `PORT` (observed: 3025) must match the routing target or pages won't be served. |
| `NVOOS_PROXY_CACHE` / `NVOOS_PROXY_CACHE_MAX_TTL` | no | Micro-cache kill-switch / TTL cap (default enabled, 300 s) |

`NVOOS_API_BASE` is a **build-time only** variable (CI) — the runtime binds
to the same-origin `/api/*` proxy and needs no origin config. Since PR #7284
the request URL takes priority over any baked `NVOOS_API_BASE` at runtime
(regression: SSR pages 500ed fetching the baked fixture origin).

## Troubleshooting

- **Pages 500 while `/api/*` returns 200** — the SSR fetch layer is hitting a
  stale/baked base. Check the mirror `dist/` for a baked fixture origin
  (`grep -r localhost:8123 dist/server/chunks/`). Fixed by the
  request-URL-first precedence in `resolveBase()` (PR #7284, regression-tested
  in `tests/unit/client.test.ts`).
- **Bridge contract 404s on the backend** — a mid-request late-loader can load
  the bridge after `rest_api_init`. `Plugin::init()` is idempotent and
  registers routes directly when hooks already fired (PR #7281). If a WPCode
  snippet (`probe-funiq-bridge`) was used as an interim fix, remove it once
  the permanent build is deployed.
- **Zip upload creates a random-suffix plugin folder** — an earlier copy of
  the folder exists. Delete both entries, then re-upload a zip whose
  top-level folder is `nvoos-funiq-bridge/` (PowerShell `Compress-Archive`;
  `tar -a` produces broken zips).

## Caching contract

| Path | Headers |
|---|---|
| `/_astro/*` (hashed chunks) | `public, max-age=31536000, immutable` (Astro standalone) |
| Prerendered PDPs | `public, s-maxage=3600, stale-while-revalidate=86400` — set per route (D4); PDP prerender pages carry route-level headers |
| SSR PLP/home | `public, s-maxage=300, stale-while-revalidate=60` |
| `sitemap.xml` / `robots.txt` | `public, s-maxage=3600` |
| `/api/*` (proxied) | Micro-cached only when the bridge marks `Cache-Control: public` (B1) — `X-Cache-Status: HIT/MISS/BYPASS` |

## A/B coexistence (owner decision Q1: keep A/B)

The licensed leg (`nvoos-funiq-velocity`) is now the **Payload admin** leg
(repackaged 2026-10-29 — the licensed item contains no web PWA; see
`docs/operations/deployment/nvoos-funiq-velocity-setup.md`). It serves the
mobile app's native Payload contract on its own origin/DB — no
web-rule splitting between the two legs is required. The split below was
written for the original manual PWA deploy and remains valid for any
storefront-level A/B the owner runs.

## Revalidation

Prerendered catalog pages update at build. The bridge's `RebuildWebhook`
(Phase 2.3) closes the loop automatically:

1. On the backend site, set the options:
   - `funiq_bridge_rebuild_webhook_url` =
     `https://api.github.com/repos/nvdigitalsolutions/mcp-ai-wpoos/dispatches`
   - `funiq_bridge_rebuild_webhook_token` = a repo-scoped PAT
2. Every catalog write (products, promotions, promocodes, terms, banner /
   carousel) fires one **debounced** (10 min) POST on shutdown.
3. The `repository_dispatch` trigger in `sync-nvoos-astro-velocity.yml`
   re-runs the sync → rebuild + mirror push → Velocity redeploys.

SSR paths (PLP/home) revalidate within `s-maxage` between builds anyway.

## A/B coexistence routing (owner decision Q1: keep A/B)

The licensed PWA app (`nvoos-funiq-velocity`) stays live as rollback. Once
both Velocity apps exist, split traffic with Velocity **web rules**
(available via the Cloudways MCP `app_web_rules_update`, type `redirect`):

- `temporary` redirect on `src: /` with condition `cookie ~ nvoos_front=astro`
  → the Astro app origin (canary by cookie).
- The inverse rule on the Astro app for `nvoos_front=pwa` (rollback).

Both apps point at the same backend, so the bridge serves both namespaces
(`nvoos-commerce/v1` + legacy) without conflict.

## Rollback

Redeploy the previous mirror commit (`git push -f` of the prior flat commit)
or flip the A/B routing back to the PWA app — no data migration involved.
