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

<img width="75%" height="75%" alt="Screenshot 2026-11-09 031400" src="https://github.com/user-attachments/assets/a439dd01-f669-4f49-9306-80f33f639021" />

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
npm run test:e2e     # Playwright T0 smoke against the Laravel runtime (see e2e/README.md)
```

The T0 smoke builds a Laravel-profile bundle into `.e2e-dist/` and serves it
through `scripts/serve.mjs`, so the committed `dist/` is never rewritten. It
needs the Laravel stack up and its origin in `SANCTUM_STATEFUL_DOMAINS` — the
prerequisites are in [`e2e/README.md`](e2e/README.md).

### Backend profile is a runtime setting

`scripts/serve.mjs` reads `NVOOS_BACKEND` (`wordpress` | `laravel` | `hybrid`),
proxies the matching prefixes, and stamps the client profile into the page it
serves: the SPA entry gets `<script src="/app-config.js">`, and that route
answers `window.__NVOOS_BACKEND__="<profile>"`. `runtime-config.ts` reads that
global first, so **one prebuilt `dist/` serves both runtimes** — switching a
deployment between WordPress and Laravel is an env-var change plus a restart,
not a rebuild. A `VITE_NVOOS_BACKEND` baked into the artifact (`.env.laravel`,
`--mode laravel`) is only the fallback when the stamp is absent (`vite dev`,
`vite preview`, an older server).

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

**Magic-link sign-in (opt-in):** the sign-in screen also offers a
passwordless "Magic link" mode against the plugin's `/magic-login/*` REST
routes (off by default — enable `wp_mcp_ai_magic_login_enabled` and point
`wp_mcp_ai_magic_login_app_origin` at the console origin). Emailed links
resolve at `#/magic-login?token=…` and confirm through the same proxy;
full contract in the deploy doc
(`docs/operations/deployment/nvoos-complete-velocity-setup.md`).

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

## Connected apps

Content Graph, Docs Hub, Media Studio and Document Editor are separate
products, usually on their own origin. `scripts/serve.mjs` can mount them
same-origin at `/connected/<key>/*` so their islands need no CORS
configuration (Proposal 090-p W6).

```bash
NVOOS_CONNECTED_APPS='{
  "contentGraph": {"base": "https://cg.example.com/wp-json/nvoos-content-graph/v1", "token": "…"},
  "docsHub":      {"base": "https://docs.example.com/wp-json/nvoos-docs/v1", "cacheTtl": 60}
}'
```

- **The upstream origin always comes from this variable, never from the
  request** — a client can only name one of the four known keys.
- Browser `Cookie`, `Authorization`, `X-WP-Nonce` and `Origin`/`Referer` are
  dropped; a configured `token` is attached server-side as a bearer
  credential. Response `Set-Cookie` and origin-policy headers are stripped.
- Responses are `Cache-Control: no-store` unless the app sets `cacheTtl`
  (seconds, capped at 900).
- Paths are refused (`400 nvoos_connected_path_invalid`) when they traverse,
  encode a separator, or are protocol-relative; an unknown key is
  `404 nvoos_connected_unknown_app`.
- The console only uses the mount when `GET /features` advertises
  `connected_proxy: true`, so a deployment without this variable keeps using
  each product's absolute URL.
- Certificate validation is on by default; `NVOOS_CONNECTED_INSECURE=1`
  disables it for a self-hosted product behind an internal CA.

Never point `NVOOS_CONNECTED_APPS` at a host you do not control.

## Open decisions

- Cross-app SSO with the Schedule Anything app (shared-domain cookie) —
  Proposal 083 §6.4 follow-up.
- Platform-provisioned default assistant (both default + selector are
  supported — see `src/runtime-config.ts`).
