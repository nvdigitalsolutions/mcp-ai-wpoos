# NV oOS Complete Console — Comprehensive Implementation Plan

> **Status:** Implementation Plan (companion to Proposal 083) · **Target:** Phase 1 MVP in ~2 focused PRs · **Platform:** Cloudways Velocity (Node static + BFF proxy) + NV oOS Complete WordPress backend
>
> **Last Updated:** 2026-10-20
> **Author:** NV Digital Solutions
>
> **Based on:** Proposal 083 (industry research: Fowler micro-frontends decomposition triggers, NashTech 2025 composition trade-offs, Auth0/Duende BFF same-origin session pattern, Veld Systems multi-product SaaS shell), Prop 077 recorded decision, PR #7148 (React 19.1 alignment), and the two proven example apps (`examples/schedule-anything-velocity`, `examples/nvoos-pro-spa-vite`).

---

## Table of Contents

1. [Architecture Decisions](#1-architecture-decisions)
2. [Repository Structure](#2-repository-structure)
3. [Host Bootstrap Contract](#3-host-bootstrap-contract)
4. [Phase-by-Phase Build Plan](#4-phase-by-phase-build-plan)
5. [Testing Strategy](#5-testing-strategy)
6. [Deploy & Sync Runbook](#6-deploy--sync-runbook)
7. [Verification Matrix](#7-verification-matrix)
8. [Follow-on Work (deferred)](#8-follow-on-work-deferred)

---

## 1. Architecture Decisions

| # | Decision | Alternatives Considered | Rationale | Industry Source |
|---|---|---|---|---|
| D1 | New dedicated Vite app `examples/nvoos-complete-velocity/` hosting spa-v2 | Embed spa-v2 `App` in the SA SPA; reuse standalone app with env presets | Different product, audience, and deploy cadence than the tenant SPA; build-time composition of a single React 19.1 tree | Fowler [Micro Frontends](https://martinfowler.com/articles/micro-frontends.html); Veld [multi-product SaaS](https://veldsystems.com/blog/multi-product-saas-architecture) |
| D2 | Build-time composition via Vite alias onto `addons/pro/assets/spa-v2/src` | Module Federation; vendoring spa-v2 sources | Single team + single repo + single React major → runtime composition adds cost with no benefit (already rejected in Prop 077 §2.3) | NashTech [Micro-frontends 2025](https://blog.nashtechglobal.com/micro-frontends-in-2025-architecture-trade-offs-and-best-practices/) |
| D3 | Cookie BFF proxy (`serve.mjs` ported byte-identical from the SA app) | CORS + bearer tokens in the browser; separate auth service | Same-origin session cookies, no CORS, no tokens in JS; pattern already proven in this repo | Auth0 [BFF pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/); Duende [BFF](https://docs.duendesoftware.com/bff/) |
| D4 | Prebuilt git-tracked `dist/` shipped to a mirror repo root (SA pattern), no build on Velocity | Build-on-Velocity; build-in-mirror (standalone-app pattern) | Velocity needs zero build step; TS paths resolve only in the monorepo (mirror-side builds are the TS2307 failure class) | Repo precedent: SA Velocity app + sync workflow |
| D5 | Sync workflow triggers on `examples/nvoos-complete-velocity/**` **and** `addons/pro/assets/spa-v2/**` | Trigger only on the app folder | The console embeds spa-v2 sources — a spa-v2 change must redeploy the console or it silently serves stale code | Repo precedent: content-graph porting lesson (shared-source drift) |
| D6 | Entry chunk budget ≤ 150 KB gzip; spa-v2 `App` lazy-mounted | Ship spa-v2 eagerly | Entry = shell only; the full surface loads after bootstrap; budget ported from `spa-bundle-size.yml` | [frontendchecklist.io](https://frontendchecklist.io/rules/javascript/code-splitting); Beefed.ai [code-splitting](https://beefed.ai/en/advanced-code-splitting-lazy-loading) |
| D7 | Host bootstrap (runtime-config, fetch wrapper, shims) copied from the standalone app with documented deltas; extraction to a shared package deferred | Extract `packages/nvoos-spa-host/` now | Two hosts don't justify the package boundary yet; copy-with-delta is the repo's proven pattern (vendored-upstream) | Repo precedent: serve.mjs "ported" between apps |

### 1.2 Technology Stack

| Layer | Technology | Purpose |
|---|---|---|
| **Console surface** | spa-v2 (React 19.1, `@ai-sdk/react`, zustand, Radix-free admin CSS) via Vite alias | All ten hash routes: chat, settings, tools, assistants, workflows, analytics, knowledge-graph, docs, media, documents |
| **Host** | Vite 8 + React 19.1 + TypeScript (mirrors the standalone app versions) | Shell bootstrap, runtime global, lazy mount |
| **Auth** | WordPress session cookie through the same-origin proxy; `wp_rest` nonce + `X-WP-Nonce` | BFF session model |
| **Server** | Zero-dependency Node (`server.js` shim → `scripts/serve.mjs`) on Velocity/PM2 | Static + reverse proxy (SSE passthrough) |
| **Backend** | NV oOS Complete bundle (base + Pro + addons) WordPress site | REST surface: `mcp-ai/v1`, `mcp-ai-pro/v1`, `wp/v2` |
| **Sync** | GitHub Actions → mirror repo `nvoos-complete-velocity` (force-push, flat history) | Velocity redeploys from the mirror |

---

## 2. Repository Structure

```
examples/nvoos-complete-velocity/
├── package.json            # type:module, main:server.js, no-op build/start, vite deps (copy of standalone app)
├── server.js               # ESM shim: import('./scripts/serve.mjs')  [SA pattern, root entry for PM2]
├── index.html              # root div + module script
├── vite.config.ts          # spa-v2 alias + resolve.dedupe (react, react-dom, react-router-dom, zustand, @ai-sdk/react, marked, dompurify)
├── tsconfig.json           # paths map copied from the standalone app (paths point ../../addons/pro/assets/spa-v2/src)
├── src/
│   ├── main.tsx            # cookie bootstrap → lazy-mount spa-v2 <App/> (NO connection screen)
│   ├── runtime-config.ts   # ported: buildRuntime(viaProxy:true), fetchSessionNonce, fetchCurrentUser, buildEndpoints
│   ├── fetch-wrapper.ts    # ported: cookie mode + empty-X-WP-Nonce strip
│   ├── shims/
│   │   ├── wp-i18n.ts      # window.wp.i18n drop-in (copy)
│   │   └── cytoscape-fcose.d.ts  # ambient module (copy)
│   └── shell.css           # WP-admin height chain + app branding (port of standalone shell.css)
├── scripts/
│   ├── serve.mjs           # BYTE-IDENTICAL proxy core from examples/schedule-anything-velocity/scripts/serve.mjs
│   └── sync-dist.mjs       # build (tsc && vite build) → wipe → copy dist/ → asset-closure gate
├── dist/                   # git-tracked prebuilt bundle (entry + lazy spa-v2 chunks)
├── README.md               # stack, runbook pointer, React 19 note, sync instructions
└── THIRD_PARTY_NOTICES.md  # spa-v2 dep set (MIT/Apache/BSD/ISC)
```

Mirror repo `nvdigitalsolutions/nvoos-complete-velocity`: repo root = app root
(SA-style), ships `server.js`, `scripts/`, `dist/`, `package.json` — no
sources, no `node_modules`, no build step.

---

## 3. Host Bootstrap Contract

The `window.NVOOS_PRO_SPA` global must satisfy spa-v2's `readProSpaConfig()`
(shape source of truth: `addons/pro/assets/spa-v2/src/api/config.ts`).

1. `origin = window.location.origin` — endpoints built against the proxy
   (`apiUrl`, `proApi`, `endpoints` incl. `contentGraph`, `docsHub`,
   `mediaStudio`, `documentEditor` defaults).
2. Nonce: `fetchSessionNonce()` (wp-admin page scrape → `mcp-ai/v1/session/nonce`
   fallback — the standalone app's proven chain).
3. User: `fetchCurrentUser()` via `wp/v2/users/me?context=edit` (cookie +
   `X-WP-Nonce`) → id/login/displayName/capabilities into `runtime.user`.
4. Config: `{ mode: 'admin', theme: 'auto', showSidebar: true, cronMonitor:
   true, assistantSelector: true, assistantId: 0, allowSensitiveTools: false }`
   — assistant provisioning decision (Prop 083 §6.2) flips `assistantSelector`
   off and pins `assistantId` when made.
5. `window.wp.i18n` shim installed before any spa-v2 code runs.
6. Fetch wrapper: cookie mode (credentials include), strip spa-v2's empty
   `X-WP-Nonce`; keep the bearer/basic paths dormant behind the same seam for
   future admin-token use.

---

## 4. Phase-by-Phase Build Plan

### Phase 0 — Decision gates (no code)

- [ ] Confirm product label ("NV oOS Complete Console") and folder name.
- [ ] Confirm target backend = customer's Complete-bundle WP site (and the
      dev/staging equivalent for the E2E harness).
- [ ] License pass: spa-v2 dep set already MIT/Apache/BSD/ISC (standalone app
      vetting); regenerate `THIRD_PARTY_NOTICES.md` for this app.

### Phase 1 — Scaffold (PR 1a: `add/nvoos-complete-console-scaffold`)

1. Copy the SA app's server files: `server.js`, `scripts/serve.mjs`
   (**byte-identical**, update only the log prefix + header comment origin
   note), no-op `build`/`start` shims, `package.json` skeleton
   (`"main": "server.js"`, `"type": "module"`).
2. Copy the standalone app's host files: `vite.config.ts`, `tsconfig.json`,
   `index.html`, `src/shell.css`, `src/shims/*` — fix paths
   (`../../addons/pro/assets/spa-v2/src`), add the spa-v2 alias +
   `resolve.dedupe` list.
3. Copy dependency versions from `examples/nvoos-pro-spa-vite/package.json`
   (react 19.1.0 exact, vite 8, @ai-sdk/react, cytoscape + types, jquery +
   types, zustand, marked, dompurify).
4. `npm install`; add `.gitignore` (node_modules only — `dist/` is tracked).
5. Write `README.md` (stack, runbook pointer, sync instructions) +
   `THIRD_PARTY_NOTICES.md`.

**Exit gate:** `npm run typecheck` passes with zero sources yet (paths +
config valid), `vite build` produces an empty-but-valid bundle.

### Phase 2 — Bootstrap wiring (PR 1b: `add/nvoos-complete-console-bootstrap`)

1. `src/runtime-config.ts` — port `buildRuntime()`, `buildEndpoints()`,
   `fetchSessionNonce()`, `fetchCurrentUser()`, `readJson()` from the
   standalone app; delete connection-screen surface (`ConnectionSettings`
   auth modes except cookie), fix `viaProxy: true` always.
2. `src/fetch-wrapper.ts` — port; cookie-mode-first.
3. `src/main.tsx` — `Promise.all([nonce, user])` bootstrap with a branded
   loading state → `buildRuntime()` → write `window.NVOOS_PRO_SPA` →
   `React.lazy(() => import('@nvoos/pro-spa-v2/App'))` → `createRoot().render`.
   Errors render a retry screen with a login link (`/wp-login.php?redirect_to=%2F`).
4. Import spa-v2 CSS (`styles/main.css`, `styles/drawers.css`,
   `styles/embedded.css`) — in the host, per the standalone app.
5. Unit tests (vitest) for `runtime-config.ts` (endpoint building, nonce
   fallback chain with mocked fetch, runtime shape) and `fetch-wrapper.ts`
   (nonce strip, cookie mode).

**Exit gate:** vitest green; `npm run build` green; bundle lists spa-v2 `App`
as a lazy chunk.

### Phase 3 — Quality gates (same PR)

- [ ] `npm run typecheck` — full spa-v2 import graph resolves (the TS2307 trap).
- [ ] `npm run lint:a11y` (jsx-a11y config copied) — host sources only.
- [ ] Determinism: two consecutive builds byte-identical (the Tailwind
      feedback-loop lesson from PR #7148 — no Tailwind here, but the gate
      stays).
- [ ] Budget: record entry chunk gzip (target ≤ 150 KB) + per-lazy-chunk
      sizes; call out growth in the PR body.
- [ ] Port `spa-bundle-size.yml` guard for this app (entry chunk).

### Phase 4 — E2E smoke (Docker, reuse the SA harness pattern)

Stand up `nvoos-complete-e2e` (nginx serving `dist/` + proxying
`/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes` to a WP 6.9 container
with the Complete bundle activated; `NVOOS_TARGET_SITE` env on `serve.mjs`
mode for local runs).

- [ ] Cookie login via the re-scoped `/wp-login.php` flow; session nonce minted.
- [ ] All ten hash routes render without console errors (visual QA for
      `shell.css` height chain + spa-v2 CSS).
- [ ] Chat SSE stream end-to-end (proxied, streaming).
- [ ] Capability checks: admin sees tools/assistants/workflows; a lower-cap
      user gets the correct gating (spa-v2 server-enforced responses).
- [ ] `/wp-json` 404s, hashed-asset 404s, and upstream-down 502 behave like
      the SA app.

### Phase 5 — Deploy plumbing (PR 2: `add/nvoos-complete-console-sync`)

1. `scripts/sync-dist.mjs` — `tsc && vite build` in the monorepo → wipe →
   copy → asset-closure gate (port of the SA script + build-first).
2. `.github/workflows/sync-nvoos-complete-velocity.yml` — path filters
   `examples/nvoos-complete-velocity/**` + `addons/pro/assets/spa-v2/**`
   (main + alpha-working) + `workflow_dispatch`; monorepo build-verify;
   smoke-test `node server.js`; force-push to mirror.
3. Create mirror repo `nvdigitalsolutions/nvoos-complete-velocity` (public,
   flat history). Deploy token secret: `NVOOS_COMPLETE_VELOCITY_REPO_TOKEN`
   (31 chars, under the 40-char limit).
4. Velocity app runbook in `docs/operations/deployment/nvoos-complete-velocity-setup.md`
   (mirror of the standalone app's doc): root dir = repo root, entry =
   `server.js` (shebang'd `serve.mjs`), env `NVOOS_TARGET_SITE`, PM2 notes.

### Phase 6 — Release bookkeeping

- [ ] Bump `version` in `package.json`; CHANGELOG entry.
- [ ] Update Prop 083 status; cross-link the plan.
- [ ] CREDITS.md / THIRD_PARTY_NOTICES.md final pass.

---

## 5. Testing Strategy

| Layer | Tooling | Scope |
|---|---|---|
| Host unit | vitest + jsdom | `runtime-config` (endpoint/nonce/user chains), fetch wrapper |
| Type | `tsc --noEmit` through the spa-v2 import graph | Full alias/paths resolution (mirror-build trap) |
| A11y | jsx-a11y + axe dev hook | Host chrome; spa-v2 keeps its own lint |
| E2E | Docker nginx + WP 6.9 + Complete bundle | Login, routes, SSE, capabilities, error paths |
| Bundle | Vite gzip report + `spa-bundle-size.yml` port | Entry ≤ 150 KB gzip |
| Determinism | Two consecutive builds byte-compare | No scan feedback loops |

spa-v2's own vitest suite is untouched (no spa-v2 changes, per G3).

---

## 6. Deploy & Sync Runbook

1. Merge to `alpha-working`/`main` → sync workflow (spa-v2 or app paths
   touched) builds + verifies + force-pushes the mirror.
2. Velocity watches the mirror repo; redeploys on new commits.
3. Manual: `npm run sync:dist` in the app folder commits the rebuilt dist
   locally; `workflow_dispatch` forces a sync when only the dist changed.
4. Post-deploy smoke: `/wp-login.php` → chat SSE → one tool call.
5. Rollback: mirror repo is flat history — redeploy the previous commit
   directly on Velocity.

---

## 7. Verification Matrix

| Gate | Command / Check | Phase | Pass criteria |
|---|---|---|---|
| TS paths | `npm run typecheck` | 1–3 | Zero errors incl. spa-v2 graph |
| Unit | `npm test` | 2 | All host tests green |
| A11y | `npm run lint:a11y` | 3 | `--max-warnings 0` |
| Build | `npm run build` | 2–3 | Exit 0; `App` in a lazy chunk |
| Determinism | build ×2 + `git diff --stat` on dist | 3 | No diff on second build |
| Budget | Vite gzip report | 3 | Entry ≤ 150 KB gzip |
| E2E | Docker smoke checklist | 4 | §4 Phase 4 all green |
| Sync | workflow run (dry push to a scratch mirror first) | 5 | Mirror root smoke-tests `node server.js` |

---

## 8. Follow-on Work (decided, tracked)

- **Cross-app SSO — shared-domain cookie (decided).** Set `Domain=<shared
  parent>` behind an env flag in this app's and the SA app's `serve.mjs`
  once both deploy on the same registered domain; runbook in
  `docs/operations/deployment/nvoos-complete-velocity-setup.md`.
- **Assistant provisioning — both (decided).** `defaultAssistantId` pins the
  platform-provisioned assistant; `assistantSelector` stays on. Product
  team supplies the default id per deployment via build-time env.
- **Standalone Pro SPA — both persist (decided).** Revisit only if usage
  data shows cannibalization.
- `packages/nvoos-spa-host/` extraction (third host triggers it).
- Media worker front-end wiring.
- Retire/replace decisions for `examples/nvoos-pro-spa-vite` (deferred).
