# NV oOS Complete Console

Cloudways Velocity app serving the **full NV oOS Pro SPA (spa-v2) surface** —
chat, settings, tools, assistants, workflows, analytics, knowledge graph,
docs, media, documents — for NV oOS Complete customers.

The console ships its **own Schedule Anything-style shell**
(`src/console/ConsoleShell.tsx`) instead of mounting spa-v2's three-column
admin layout: a collapsible left nav, a **Dashboard home page**, one page per
addon (`#/assistants`, `#/tools`, …), and a **persistent chat dock** on the
right behind a global toggle. `/chat` is a full-page breakout (builder-page
style) for when the conversation needs the whole viewport. The chat surface
is rendered once and moved between dock and full-page purely via CSS, so a
conversation keeps streaming while you browse.

Cookie-authenticated console (no connection screen): the zero-dependency
`scripts/serve.mjs` serves the prebuilt `dist/` and proxies WordPress
(`/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes`) same-origin — the
BFF session pattern. spa-v2 sources are compiled in via the
`@nvoos/pro-spa-v2` Vite alias (**no fork** — tracks
`addons/pro/assets/spa-v2`).

Proposal: `docs/project/proposals/083-nvoos-complete-console-app-proposal.md` ·
Plan: `docs/project/plans/nvoos-complete-console-app-implementation.md` ·
Deploy: `docs/operations/deployment/nvoos-complete-velocity-setup.md`

## Stack

React 19.1 + Vite 8 + TypeScript host; spa-v2 pages lazy-mounted per route
(entry chunk budget ≤ 150 KB gzip). Same host recipe as
`examples/nvoos-pro-spa-vite` (alias, tsconfig paths, shims) minus the
connection screen.

## Console shell

`src/console/` holds the host shell:

| File | Role |
|---|---|
| `ConsoleShell.tsx` | Bootstrap gate + `HashRouter`; owns the single `useTranscripts` instance (shared by dock, sidebar, and full page) and the persisted dock toggle |
| `ConsoleSidebar.tsx` | SA-format nav rail (collapsible, active states) + global chat-dock toggle |
| `DashboardPage.tsx` | Home page: welcome card, live stats, quick-action cards |
| `ChatDock.tsx` | The one chat surface — spa-v2 `ChatSidebar` + `ChatPage`; `docked` / `fullpage` / hidden via CSS only (never unmounted) |
| `session-utils.ts` | Pure session-title helper (dock header), unit-tested |

Routes: `/` dashboard · `/chat` full-page chat · `/assistants` · `/tools` ·
`/workflows` · `/analytics` · `/knowledge-graph` · `/docs` · `/media` ·
`/documents` · `/settings` (catch-all → `/`). The dock preference persists in
`localStorage` (`nvoos-complete-console.dock-open`); the nav collapse in
`nvoos-complete-console.nav-collapsed`.

## Quick start

```bash
cd examples/nvoos-complete-velocity
npm install
npm run dev          # Vite dev on :5198; set NVOOS_TARGET_SITE for cookie proxy
npm run build        # tsc && vite build → dist/
npm run typecheck    # full spa-v2 import graph must resolve
npm test             # vitest (host bootstrap)
npm run lint:a11y
npm run serve        # node scripts/serve.mjs (needs dist/ + NVOOS_TARGET_SITE)
```

## Deploy sync

`npm run sync:dist` builds and stages `dist/` (git-tracked). Merges touching
this folder or `addons/pro/assets/spa-v2/**` trigger
`sync-nvoos-complete-velocity.yml` → mirror repo
`nvdigitalsolutions/nvoos-complete-velocity` (flat history, force-push) →
Velocity redeploys. Never commit to the mirror directly. The assembled
mirror rewrites the monorepo-only npm scripts (`build`, `dev`, `test`, …)
to no-op shims — it ships the prebuilt `dist/` only, so a Velocity build
step, if enabled, succeeds without a real build. Dependencies are stripped
too (the runtime is zero-dependency Node), keeping Velocity's `npm install`
a silent no-op with no audit noise.

## Auth

WordPress session cookie through the proxy; login via the re-scoped
`/wp-login.php` flow. The `wp_rest` nonce is scraped from `/wp-admin/` with
the plugin's `mcp-ai/v1/session/nonce` endpoint as fallback; the real user +
capabilities come from `wp/v2/users/me?context=edit`.

## Caching

`scripts/serve.mjs` keeps load off the WordPress backend on three fronts:

- **Hashed `/assets/*` → `public, max-age=31536000, immutable`** — the
  browser and the Velocity CDN cache every chunk for a year (Vite hashes
  rotate URLs on redeploy).
- **`index.html` → `no-cache` + ETag** — revalidation returns 304s instead
  of re-downloads; unversioned root files (favicon) get `max-age=86400` +
  ETag.
- **gzip/brotli from an in-memory file cache** (`Vary: Accept-Encoding`).
- **Default-deny proxy micro-cache** — proxied `GET` responses are cached
  only when WordPress marks them `Cache-Control: public` with an explicit
  TTL and no `Set-Cookie`/`Vary: Cookie`; nonce + SSE (`no-store`) can never
  be cached. Observability via `X-Cache-Status: HIT/MISS/BYPASS`. Disable
  with `NVOOS_PROXY_CACHE=0`; cap TTL with `NVOOS_PROXY_CACHE_MAX_TTL`
  (default 300 s). Full contract + backend opt-in guide:
  `docs/operations/deployment/nvoos-complete-velocity-setup.md`.

## Open decisions

- Cross-app SSO with the Schedule Anything app (shared-domain cookie) —
  Proposal 083 §6.4 follow-up.
- Platform-provisioned default assistant (both default + selector are
  supported — see `src/runtime-config.ts`).
