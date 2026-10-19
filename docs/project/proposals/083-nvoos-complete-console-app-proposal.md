# Proposal 083 — NV oOS Complete Console: Full Pro Surface as a Dedicated Velocity App

- **Date:** 2026-10-20
- **Status:** Draft (research complete, direction approved; open questions resolved 2026-10-20 — see §6)
- **Surfaces:** new `examples/nvoos-complete-velocity/` (Vite host + zero-dependency Velocity server), `addons/pro/assets/spa-v2` (mounted via Vite alias — **no spa-v2 changes**), `.github/workflows/sync-nvoos-complete-velocity.yml` (new), mirror repo `nvdigitalsolutions/nvoos-complete-velocity` (new)
- **Tracks:** standalone SPA hosting, Velocity deployment, NV oOS Complete product surface
- **Follows:** PR #7148 (Schedule Anything SPA → React 19.1 — the enabler), Proposal 082 (SA single-site mode), Proposal 077 (SPA hub single shell), Proposal 064 (standalone SPA WordPress login)

## 1. Problem Statement

NV oOS ships three hosted SPA surfaces today, none of which delivers the full
Pro console as a branded, cookie-first product for NV oOS Complete customers:

1. **The Schedule Anything tenant SPA** (`examples/schedule-anything-velocity`)
   is a Pattern-B product app (React 19.1 after PR #7148) for one business
   domain — schedules, presets, toolkits, booking. It is *not* the AI console.
2. **The standalone Pro SPA** (`examples/nvoos-pro-spa-vite`) does mount the
   full spa-v2 admin surface, but it is a generic *connection-screen* app:
   four auth modes, no product identity, and an onboarding flow (pick a site,
   paste a credential) that is wrong for a product we provision for the
   customer.
3. **spa-v2 itself** is the right surface — the complete console (chat,
   assistants, tools, workflows, analytics, knowledge graph, docs, media,
   documents) — but it only exists as a WP-admin/plugin bundle and as the
   standalone connection-screen mount.

Earlier analysis evaluated mounting the spa-v2 `App` **inside** the Schedule
Anything SPA as a route (Option B of the "Full Pro surface" discussion). That
works technically post-React-19, but it conflates two products in one bundle
and one deployable. The user's instinct — a **new Vite app, scaffolded from
the proven Schedule Anything Velocity pattern** — is the option the industry
research below independently endorses, and it aligns with the repo's own
recorded decisions.

Goals:

- **G1** — Ship the full spa-v2 admin surface (`mode: 'admin'`, all ten hash
  routes) as a standalone, Complete-branded console app on Cloudways Velocity,
  cookie-authenticated against the customer's Complete-bundle WordPress site.
- **G2** — Reuse proven repo infrastructure byte-for-byte where possible: the
  SA `serve.mjs` BFF proxy core, the SA mirror-sync workflow pattern, the
  standalone app's Vite alias + TS-paths + shim recipe for spa-v2.
- **G3** — No changes to `addons/pro/assets/spa-v2` sources; the console
  tracks the plugin SPA (same contract as the standalone app).
- **G4** — Entry chunk ≤ 150 KB gzip (repo standard; frontendchecklist.io
  starting budget), spa-v2 surface lazy-loaded.

Non-goals for this proposal: cross-app single sign-on mechanics (decided —
shared-domain cookie, deferred to a follow-up; see §6.4), the media-worker
front-end, embedding the checkout upsell, retiring the standalone
connection-screen app (both persist for now), and any spa-v2 plugin-bundle
changes.

## 2. Research & Industry Standards

### 2.1 When to split frontends — decomposition triggers

- Micro-frontends are defined as "an architectural style where independently
  deliverable frontend applications are composed into a greater whole"
  (Fowler, [micro-frontends article](https://martinfowler.com/articles/micro-frontends.html)).
  The trigger is not technical fashion but **independent deliverability**:
  different release cadences, teams, or audiences per surface. Here the
  triggers are audience and product boundary: the Schedule Anything SPA is a
  tenant-facing domain product; the Pro console is the AI-administration
  surface for Complete customers. They ship, brand, and scale differently.
- Nearform's guidance is the same: adopt micro-frontend composition only when
  product boundaries warrant it, and regardless of split, **code-split so only
  necessary code loads per route**
  ([When and why to use micro-frontends](https://nearform.com/digital-community/when-and-why-to-use-micro-frontend-architecture/)).
- Consequence: a dedicated deployable for the console is the principled
  choice, *not* a route welded into the Schedule Anything bundle (which would
  couple two products' deploy cadences and bundle budgets).

### 2.2 Composition method — build-time beats runtime here

Three composition families exist; the trade-offs are well documented (Fowler,
above; NashTech, [Micro-frontends in 2025](https://blog.nashtechglobal.com/micro-frontends-in-2025-architecture-trade-offs-and-best-practices/)):

| Method | Isolation | Integration | Complexity | Verdict for us |
|---|---|---|---|---|
| Iframes | Strongest | Weakest (state, deep links, styling) | Low | Reserved for third-party apps (Prop 077 §3.2.1) |
| **Build-time (monorepo, shared source)** | None (one bundle) | Full (one React tree, shared state) | **Lowest** | **Chosen** — single team, single repo, single React 19.1 tree |
| Runtime (Module Federation, single-spa) | Medium | Medium | High (runtime orchestration, version skew) | Rejected — Prop 077 §2.3 already records this rejection |

- Build-time composition via a Vite alias onto `addons/pro/assets/spa-v2/src`
  is exactly the standalone app's proven pattern (no fork, tracks the plugin
  SPA). The repo's own recorded decision (Prop 077 §3.2.1) — heavy React SPAs
  stay separate surfaces rather than being vendored into one another — is
  respected here in the stronger direction: **spa-v2 is the shell; it is
  hosted, not merged into another shell.**
- Monorepo guidance confirms the shared-package direction: "all products live
  in one repository; shared UI components, auth hooks, and API clients live in
  shared packages" (Veld Systems,
  [Multi-product SaaS architecture](https://veldsystems.com/blog/multi-product-saas-architecture)).
  The shared pieces here are spa-v2 itself plus the host-bootstrap layer
  (runtime-config, fetch wrapper, shims) — initially copied with documented
  deltas, extractable later (§4.5).

### 2.3 Session model — the BFF / same-origin proxy is the standard

- The Backend-for-Frontend pattern exists precisely for cookie-based SPA
  sessions: the BFF "proxies all requests to the API" and "the SPA only
  interacts with the backend, relying on traditional cookies for
  authenticated sessions" (Auth0,
  [The Backend for Frontend pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/));
  serving SPA and API from one origin **eliminates CORS complexity** and keeps
  tokens out of JavaScript (Duende, [BFF security framework](https://docs.duendesoftware.com/bff/)).
- The Schedule Anything `serve.mjs` *is* this pattern: static SPA + reverse
  proxy for `/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes`, login
  page re-scoped to the app origin, `Set-Cookie` Domain stripped so the
  session lives on the app host, SSE streams piped through. Porting it
  byte-identical (its documented intent — see its header comment) gives the
  Complete console the same security posture for free.

### 2.4 Multi-product SaaS shell

- The industry shape for multi-product SaaS is "a shell that handles
  navigation, auth state, and product switching, with individual product UIs
  loaded as modules or routes within that shell" (Veld Systems, above).
- Mapped to NV oOS: the **Complete Console** (spa-v2) is the shell — chat,
  assistants, tools, workflows, analytics, and the framed/ported product
  surfaces it already routes (`#/knowledge-graph`, `#/docs`, `#/media`,
  `#/documents`). Schedule Anything remains a sibling product app. This is the
  deployment-layer realization of the Prop 077 single-shell vision, without
  the vendoring burden 077's Track B correctly declined.

### 2.5 Performance budgets

- A JavaScript budget keeps bytes off the critical path: "a common starting
  point is ≤ 150 KB gzipped for the main route bundle"
  ([frontendchecklist.io — code splitting](https://frontendchecklist.io/rules/javascript/code-splitting)),
  and "splitting by route is the highest-leverage move in most SPAs" (Beefed.ai,
  [advanced code-splitting](https://beefed.ai/en/advanced-code-splitting-lazy-loading)).
- Consequence: the console host lazy-mounts spa-v2's `App` (the standalone app
  already proves this — its entry chunk is just the shell), keeps the
  connection-screen-free entry lean, and adopts the repo's
  `spa-bundle-size.yml`-style gate.

## 3. Proposed Design

### 3.1 Options considered

| | A — Embed spa-v2 `App` in the SA SPA | B — New dedicated Velocity app (**chosen**) | C — Second instance of the standalone app |
|---|---|---|---|
| Deliverable | One bundle, one deploy | New `examples/nvoos-complete-velocity/` | Reuse `nvoos-pro-spa-vite` with env presets |
| Product fit | Conflates tenant app + console | Console is its own product surface | Generic connection-screen identity remains |
| Bundle coupling | Two products share budgets & deploys | Independent budgets & cadence | Independent |
| Auth | SA cookie flow | Cookie BFF proxy (proven) | Connection screen / presets |
| Effort | Medium (route + bootstrap) | Medium (scaffold + bootstrap + sync) | Lowest (config only) |
| Research alignment | Weak (decomposition triggers, §2.1) | Strong (§2.1–2.5) | Weak (product identity, §1) |

Option B wins on product fit and industry alignment at a modest one-time
scaffold cost, and it hardens into a repeatable recipe for future surfaces.

### 3.2 Shape of the new app

`examples/nvoos-complete-velocity/` is a hybrid of the two proven examples:

- **From `examples/schedule-anything-velocity`** (the deployable skeleton):
  `package.json` (`"type": "module"`, `"main": "server.js"`, no-op build/start
  shims), root `server.js` ESM shim, `scripts/serve.mjs` **byte-identical**
  proxy core (no runtime-env injection — there is no connection screen),
  prebuilt git-tracked `dist/`, `scripts/sync-dist.mjs` with the asset-closure
  gate, mirror-sync GitHub workflow, mirror repo at the repo **root**.
- **From `examples/nvoos-pro-spa-vite`** (the Vite host): `vite.config.ts`
  (spa-v2 alias + `resolve.dedupe`), `tsconfig.json` paths map + shims
  (`wp-i18n.ts`, `cytoscape-fcose.d.ts`), `src/shell.css`, and the
  host-bootstrap layer — **minus the connection screen**. `src/main.tsx`
  bootstraps cookie mode directly (no `Shell` connection UI), lazy-mounts
  spa-v2's `App`, and renders it. spa-v2's `Layout` provides the internal
  `HashRouter` (verified: `addons/pro/assets/spa-v2/src/components/layout/Layout.tsx`),
  so all ten hash routes (`#/chat` … `#/documents`) work unchanged.

Bootstrap contract (port of the standalone `runtime-config.ts` with fixed
settings):

- `origin = window.location.origin` (`viaProxy: true` — the serve.mjs proxy
  makes REST same-origin; no CORS work).
- Auth = cookie: login via the re-scoped `/wp-login.php` flow; `wp_rest` nonce
  from the same source the SA SPA uses (platform/auth nonce endpoint) with the
  standalone app's `mcp-ai/v1/session/nonce` fallback; real user +
  capabilities via `wp/v2/users/me?context=edit`.
- `window.NVOOS_PRO_SPA` built exactly as spa-v2's `readProSpaConfig()`
  expects (`mode: 'admin'`); `window.wp.i18n` shim installed before mount;
  fetch wrapper limited to cookie mode (drop the empty `X-WP-Nonce` spa-v2
  sends — the proven recipe).
- `config.assistantId` defaults to 0 (assistant selector per session) — the
  per-tenant provisioning question stays with the product team (§6.2).

### 3.3 Deployment & sync

- Mirror repo `nvdigitalsolutions/nvoos-complete-velocity` (public, flat
  history, force-push only) — app at the repo root, exactly like the SA
  mirror. Velocity PM2 entry = root `server.js` shim → `scripts/serve.mjs`
  (shebang mandatory), env `NVOOS_TARGET_SITE=<Complete-bundle WP site>`.
- Sync workflow `sync-nvoos-complete-velocity.yml` triggers on pushes touching
  `examples/nvoos-complete-velocity/**` **and** `addons/pro/assets/spa-v2/**`
  (the console embeds spa-v2 sources — a spa-v2 change must redeploy the
  console) on main + alpha-working, plus `workflow_dispatch`. It builds
  (typecheck + vite build) in the monorepo where the TS paths resolve —
  avoiding the mirror-side TS2307 class of failure recorded in the SPA UI
  skill — then assembles dist + server files, smoke-tests `node server.js`,
  and force-pushes.
- Deploy-token secret: `NVOOS_COMPLETE_VELOCITY_REPO_TOKEN` (31 chars — under
  the 40-char GitHub secret limit that bit the SA track).
- Bundle gate: port `spa-bundle-size.yml` for this app's entry chunk
  (≤ 150 KB gzip); spa-v2's lazy pages are their own chunks (the standalone
  app already demonstrates real chunking, e.g. the 213 KB-gzip graph page).

### 3.4 Compliance

- All spa-v2 deps are MIT/Apache/BSD/ISC (already vetted for the standalone
  app); the console ships its own `THIRD_PARTY_NOTICES.md` and updates root
  `CREDITS.md` (SPA skill rule).

### 3.5 Follow-on extractions (deferred, tracked)

- Shared host package `packages/nvoos-spa-host/` for runtime-config, fetch
  wrapper, shims — extracted once a third host exists (standalone app,
  Complete console, …). Deferred to avoid premature abstraction.

## 4. Implementation Plan

Detailed in [`docs/project/plans/nvoos-complete-console-app-implementation.md`](../plans/nvoos-complete-console-app-implementation.md).
Phases: **0** decision gates → **1** scaffold (copy + adapt) → **2** bootstrap
wiring → **3** quality gates (typecheck through the spa-v2 import graph,
a11y, vitest, deterministic build, budget) → **4** E2E smoke on a Docker
stack (cookie login, SSE chat, all ten routes, capability checks) → **5**
deploy plumbing (sync-dist, workflow, mirror, Velocity runbook) → **6**
release bookkeeping.

## 5. Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| TS paths incomplete → mirror/Velocity build fails (TS2307 class) | High | Copy the standalone app's full paths map verbatim; CI builds in the monorepo, not the mirror; §Plan Phase 3 gate |
| spa-v2 bundle growth (full surface, ~10 lazy chunks) | Medium | Entry stays shell-only (lazy `App`); per-chunk sizes recorded in the PR body; budget workflow ported |
| spa-v2 CSS vs host styles (WP-admin height chain) | Medium | Port `shell.css`; visual QA pass in Phase 4 smoke; spa-v2 classes are `nvoos-pro-spa-*`-prefixed |
| Cookie session confusion across app hosts (console vs SA app on different subdomains) | Medium | Documented open question §6.4; per-app login is the Phase-1 behavior |
| spa-v2 drift between plugin bundle and console build | Low | Sync triggers on spa-v2 paths; console tracks `alpha-working` spa-v2 like the standalone app |
| Velocity deploy misconfiguration (entry file, shebang) | Medium | Reuse the SA app's proven server.js/serve.mjs pair verbatim; workflow smoke-tests `node server.js` |

## 6. Resolved Decisions (2026-10-20)

1. **Product naming/branding** — product label "NV oOS Console App"; folder
   `examples/nvoos-complete-velocity/`; mirror
   `nvdigitalsolutions/nvoos-complete-velocity`. ✅
2. **Assistant provisioning** — **both**: the runtime supports a
   platform-provisioned default assistant (`defaultAssistantId`) while the
   per-session selector stays enabled (`assistantSelector: true`).
   Implemented in `src/runtime-config.ts`. ✅
3. **Relationship to the standalone Pro SPA** — **both persist for now**;
   the Console is the Complete-customer surface, the connection-screen app
   remains the generic demo/connection tool. Revisit if one cannibalizes
   the other. ✅
4. **Cross-app SSO** — **yes, shared-domain cookie**. Deferred to a
   follow-up: set `Domain=<shared-parent>` behind an env flag in both
   `serve.mjs` proxies once both apps ship on the same registered domain
   (deploy runbook documents the mechanics). ✅
5. **Media worker** — omit for v1; the runtime shape already reserves the
   wiring (same as the standalone app's dormant options).

## 7. References

- Repo: Proposal 077 (SPA hub single shell + recorded decision §3.2.1),
  Proposal 064 (standalone SPA WP login), Proposal 060 (SPA UI stack),
  PR #7148 (SA SPA React 19), `examples/schedule-anything-velocity/scripts/serve.mjs`,
  `examples/nvoos-pro-spa-vite/` (runtime-config, fetch wrapper, tsconfig, shims),
  `mcp-ai-schedule-anything-ops` + `mcp-ai-wpoos-spa-ui` skills.
- Industry: Fowler — [Micro Frontends](https://martinfowler.com/articles/micro-frontends.html);
  Nearform — [When and why to use micro frontends](https://nearform.com/digital-community/when-and-why-to-use-micro-frontend-architecture/);
  NashTech — [Micro-frontends in 2025](https://blog.nashtechglobal.com/micro-frontends-in-2025-architecture-trade-offs-and-best-practices/);
  Auth0 — [BFF pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/);
  Duende — [BFF security framework](https://docs.duendesoftware.com/bff/);
  Veld Systems — [Multi-product SaaS architecture](https://veldsystems.com/blog/multi-product-saas-architecture);
  frontendchecklist.io — [code splitting](https://frontendchecklist.io/rules/javascript/code-splitting);
  Beefed.ai — [advanced code-splitting](https://beefed.ai/en/advanced-code-splitting-lazy-loading).
