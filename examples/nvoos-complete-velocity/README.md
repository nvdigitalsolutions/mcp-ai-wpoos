# NV oOS Complete Console

Cloudways Velocity app serving the **full NV oOS Pro SPA (spa-v2) admin
surface** — chat, settings, tools, assistants, workflows, analytics,
knowledge graph, docs, media, documents — for NV oOS Complete customers.

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

React 19.1 + Vite 8 + TypeScript host; spa-v2 admin surface lazy-mounted
(entry chunk budget ≤ 150 KB gzip). Same host recipe as
`examples/nvoos-pro-spa-vite` (alias, tsconfig paths, shims) minus the
connection screen.

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

## Open decisions

- Cross-app SSO with the Schedule Anything app (shared-domain cookie) —
  Proposal 083 §6.4 follow-up.
- Platform-provisioned default assistant (both default + selector are
  supported — see `src/runtime-config.ts`).
