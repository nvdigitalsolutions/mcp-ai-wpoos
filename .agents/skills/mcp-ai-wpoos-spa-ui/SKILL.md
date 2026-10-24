---
type: Skill
name: mcp-ai-wpoos-spa-ui
description: "UI stack, build, and test conventions for NV oOS React SPA addons — the three sanctioned build patterns; the standalone Pro SPA and Complete Console apps with the spa-v2 ↔ console wiring map and two-bundle rule; the island-embed pattern (Proposal 087) with the host token bridge; NDS tokens, Tailwind v4, @xyflow/react v12, vitest/jsdom gotchas, the full-JS-rebuild map, and the TS-shadows-JS gotcha. Use when building SPA addon UI, writing SPA JS tests, or rebuilding JS."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.2.11"
  plugin-version-tested: "1.2.11"
  last-updated: "2026-10-25"
---

# NV oOS SPA UI Stack & Testing Guide

Operational guide for the React SPA addons. Canonized from proposal **060 —
SPA UI Stack Enhancement** (PR #6952) — research, implementation plan, and
verification notes live in
[`docs/project/proposals/060-spa-ui-stack-enhancement.md`](../../../docs/project/proposals/060-spa-ui-stack-enhancement.md)
and
[`060-spa-ui-stack-enhancement-implementation-plan.md`](../../../docs/project/proposals/060-spa-ui-stack-enhancement-implementation-plan.md).
The canonical addon pattern itself is
[`docs/addons/toolkit-spa-blueprint.md`](../../../docs/addons/toolkit-spa-blueprint.md).

## When to use this skill

- Building or modifying React UI in `addons/toolkit-shell`, `addons/chat-spa`,
  `addons/schedule-anything-spa`, or a new toolkit SPA
- Choosing which SPA build pattern a new addon should use
- Adding a component, dialog, select, table, or form to a SPA addon
- Writing or repairing SPA JS tests (vitest) — for **PHPUnit** work use the
  `mcp-ai-wpoos-test-suite` skill instead
- Upgrading a SPA dependency (React, Tailwind, @xyflow/react, Radix) and
  re-bundling
- Rebuilding "all the JS" so packaged ZIPs carry up-to-date artifacts — see
  "Full JS rebuild map"
- Triaging webpack "export 'X' was not found … possible exports" warnings —
  see "TS-shadows-JS resolution gotcha"
- "Continue the 060 work" / P1 items (zod size pass, per-page i18n sweep,
  table virtualization)
- Building or deploying the standalone Pro SPA app
  (`examples/nvoos-pro-spa-vite/`, Cloudways Velocity, `chat.nvoos.cloud`)
  — see "Standalone SPA app (pattern D)"

## The three sanctioned build patterns — pick one, don't mix

| Pattern | Addons | Stack | Choose when |
|---|---|---|---|
| **A — standalone bundle** | `toolkit-shell`, `chat-spa` | esbuild IIFE, **bundled React 19.1**, hand-rolled or token CSS, `@wordpress/i18n` externalized to `window.wp.i18n` | The SPA embeds on **front end and admin** and must not depend on WP core scripts (any WP 6.x) |
| **B — Vite product SPA** | `schedule-anything-spa` | Vite 6, **React 19.1** (deliberate deviation from the 18.3 pin as of 2026-10-20 — see rule 1), Tailwind v4, `@wordpress/api-fetch` + i18n bundled | Tenant-facing product with a public (unauthenticated) surface and its own brand |
| **C — WP-core externals** | `saas-controller` | `wp-scripts`, `@wordpress/components`/`element`/`icons` externalized to `window.wp.*`, React 18 from WP core | Admin-only settings surfaces where zero-KB bundle and native wp-admin look matter |

Rules that follow from the matrix:

1. **React version constraint:** WP core ships React 18 through WP 7.1; the
   React 19 core upgrade is experimental in Gutenberg. Pattern C runs on
   WP's React 18 (it externalizes `window.wp.*`). Pattern A ships its own
   React 19 — fine in isolation. Pattern B (`schedule-anything-spa`) ships
   React 19.1 as of 2026-10-20 — a deliberate deviation because the SPA is
   served standalone (Velocity / nginx / built zip) and is never embedded
   inline in a WP page; its only WP deps (`@wordpress/api-fetch` +
   `@wordpress/i18n`) are React-free, so the WP-core React 18 constraint does
   not apply. If an inline wp-admin embed is ever added, revert to 18 (or
   keep the embed iframe/standalone).
2. **Dual-React guard (Pattern A):** a bundled React 19 tree must never import
   `@wordpress/element` or `@wordpress/components` (they pull WP's React 18
   renderer and corrupt hooks state). Only the React-free `@wordpress/i18n` is
   allowed. Enforce with ESLint `no-restricted-imports` (see
   `addons/toolkit-shell/eslint.config.js`). Pattern B may use
   `@wordpress/api-fetch` + `@wordpress/i18n` — both React-free.
3. Never mix patterns in one addon (no `@wordpress/components` inside a
   Pattern-A bundle, no bundled React inside a Pattern-C admin page).

## Standalone SPA app (pattern D — Vite shell over spa-v2)

`examples/nvoos-pro-spa-vite/` is a standalone Vite + React app that mounts the
**Pro SPA v2 sources directly via a Vite alias**
(`../../addons/pro/assets/spa-v2/src`) and connects to **any WordPress backend**
running the plugin — no WP host page, no fork (the app tracks the plugin SPA).
Deploy playbook: `docs/operations/deployment/nvoos-pro-spa-velocity-setup.md`.

### The spa-v2 ↔ Complete Console wiring map (connecting the dots)

Agents repeatedly lose the connection between
`examples/nvoos-complete-velocity/` (and the standalone app) and
`addons/pro/assets/spa-v2/`. Both apps **alias spa-v2 sources** — they are
NOT forks — so a spa-v2 source change only reaches the console when BOTH
bundles are rebuilt, and every spa-v2-side contract has a mirror inside the
app. Read this map when landing in either folder:

| spa-v2 source | Console mirror / consumer | Sync rule |
|---|---|---|
| `addons/pro/assets/spa-v2/src/**` | aliased as `@nvoos/pro-spa-v2` in the console `vite.config.ts` **and** `vitest.config.ts` (the vitest config must repeat every alias + `server.fs.allow: [appRoot, repoRoot]`) | edit spa-v2 once; never copy |
| `src/api/config.ts` (`ProSpaRuntime` / `ProSpaEndpoints` / `readProSpaConfig`) | `examples/nvoos-complete-velocity/src/runtime-config.ts` `buildConsoleRuntime()` builds the same `window.NVOOS_PRO_SPA` shape; the PHP emitter `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` is the canonical shape both apps mirror | new endpoint key = 4 wiring points; island PRs must update the console's endpoint VALUES too |
| `src/stores/uiStore.ts` | shared by both trees — the console shell renders `data-theme` from `useUIStore().theme`, so spa-v2 pages can follow the console theme | one store instance per app tree (alias forces the same module) |
| `src/styles/main.css` / `drawers.css` / `embedded.css` | imported by the console `main.tsx`; console-only rules live in `src/shell.css` | never move spa-v2 tokens into shell.css or vice versa |
| island bundles (`addons/media-studio/assets/dist/*`, `addons/document-editor/assets/dist/*`) | both apps import them via `?url` in their `runtime-config.ts`; the console `vitest.config.ts` needs `fs.allow` for them | `?url` imports are per-app — update BOTH runtime-configs |
| vendored island (`src/features/docs-hub/upstream/`) | consumed by both apps through spa-v2's DocsPage | byte-identical upstream discipline |

**"My spa-v2 fix doesn't show in the console" checklist** (the failure order
seen live): (1) did the console build even pass — `tsc --noEmit` fails with
TS2741 the moment a required endpoint key is missing, and nobody notices
because nobody rebuilds; (2) was the console `dist/` rebuilt in the SAME PR
— the sync workflow ships the committed dist and never runs Vite; (3) are
both apps' `buildEndpoints()` key sets AND values in sync — diff them
against each other; (4) did the console `runtime-config.ts` get the new
island `?url` imports; (5) is the feature the page renders gated by the
server feature map (`fetchFeatureMap`) — an unavailable feature empties the
endpoint and renders the unavailable state, which looks like "my code is
not running".

Reusable mechanics (all learned the hard way — do not rediscover):

- **Runtime global:** spa-v2 expects `window.NVOOS_PRO_SPA` (`apiUrl`,
  `proApi`, `nonce`, `endpoints`, `user`, `assistants`, `config`) localized by
  the plugin's PHP loader. The app builds the same object from a connection
  screen: `{site}/wp-json/mcp-ai/v1/{chat,chat-client,chat-transcripts,…}` +
  `{site}/wp-json/mcp-ai-pro/v1/{workflows,…}`, and mints the `wp_rest` nonce
  from `mcp-ai/v1/session/nonce` (**cookie mode only**).
- **Shims:** a tiny `@wordpress/i18n` drop-in (spa-v2 reads `window.wp.i18n`),
  and a `window.fetch` wrapper that injects
  `Authorization: Bearer cred_…` / `X-WP-MCP-AI-Guest` on **every** request —
  including the SSE chat stream (the AI SDK adapter opens it through global
  fetch) — and **strips the empty `X-WP-Nonce`** the SPA sends, which the
  plugin's REST layer rejects for token auth.
- **Full screen:** `shell.css` re-creates the WP-admin height chain
  (`#wpwrap` → root) the three-column layout expects.
- **Auth modes (four):** assistant credential (bearer) = the production
  cross-origin path; guest = public chat; cookie = dev proxy **or** the
  production `serve.mjs` proxy (login via `/wp-login.php?redirect_to=%2F` —
  keep `redirect_to` **relative**, `wp_validate_redirect()` rejects
  cross-host absolute URLs); **WordPress login (`wp`)** = Application
  Password (Basic auth over REST) — `wp/v2/users/me?context=edit`
  validation, the **real user + capabilities** mounted (full admin surface,
  real server-side enforcement), the Basic header built UTF-8-safe
  (`TextEncoder`) and attached **only** to the configured site origin
  (never the media worker/third-party URLs). Transcripts/approvals accept
  bearer credentials too (reads scoped to the issuing assistant;
  `approve`/`deny` stay `manage_options` — issue #6987 closed by #6990).
  **Cookie-mode login is re-scoped through the proxy (PR #6999):** WordPress
  renders `wp-login.php` with an **absolute** form action + absolute
  `redirect_to`, so cross-host deploys set the auth cookies on the
  WordPress origin and the app origin never sees the session (localhost
  masks it — cookies scope by host, not port). `serve.mjs` buffers **only**
  the login page (text/html, identity encoding, 4 MB cap) and rewrites both
  to the app origin; the Vite dev proxy mirrors the same rewrite; the
  cookie-mode error message must point at the **runtime** `NVOOS_TARGET_SITE`
  (managed Node hosting sets runtime env, not build-time `VITE_*`).
- **CORS:** the backend must echo the app origin — plugin **≥1.2.3**
  `cors_allowed_origins` (or the §5b mu-plugin) + widen
  `wp_mcp_ai_cors_guard_route_prefixes` with `/mcp-ai-pro/v1` (the default
  scope is `/mcp-ai/v1` only); the OOS SSE chat stream also emits the
  resolved header (`emit_stream_cors_headers()`). Never flip the dropdown
  to "Allow All".
- **Build/deploy:** `npm run build` → static `dist/`; `scripts/serve.mjs` is
  the Velocity entry and doubles as the **zero-dependency production
  reverse proxy** when the **runtime** env var `NVOOS_TARGET_SITE` is set
  (`/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes`; SSE streaming,
  login redirect + `Set-Cookie` Domain rewriting, hop-by-hop filtering,
  502 on upstream failure). Dev (`scripts/dev.mjs`) resolves
  `NVOOS_TARGET_SITE` with shell > `.env` > `http://localhost:8000`
  precedence (Vite `loadEnv` with an empty prefix makes non-`VITE_` keys
  load); build-time `VITE_DEFAULT_SITE_URL` pre-fills the connection
  screen. Velocity **root directory must be `examples/nvoos-pro-spa-vite`**
  — the repo root has no `package.json` and the build dies with `npm error
  enoent` (exit 254); the sync/deploy workflows pin `main` at git init
  (`git init -q -b main`) and assemble the deploy tree **under `examples/`**
  (a bare `git init` + root-level copy broke both). Both workflows now
  **build-verify the assembled tree** (`npm ci && npm run build`) before
  the push, and the mirror repo (`nvoos-pro-spa-standalone`) keeps an
  intentionally flat history (one commit per sync, force-pushed) —
  mirror-only build drift fails in the monorepo CI instead of at Velocity.
  **Velocity executes the Entry File through the shell (PRs #7004/#7006/#7009):**
  `serve.mjs` must carry a `#!/usr/bin/env node` shebang (without it, `sh`
  parses the ESM and PM2 restarts it ~15 times — `FAILED (rc=-1)`); a
  `server.on('error')` handler makes PM2's generic "process crashed (at
  least 15 restart(s) detected)" self-diagnosing (print code + message,
  hint `EADDRINUSE` → check `pm2 list`); the static branch serves
  `X-Content-Type-Options: nosniff` + `Strict-Transport-Security:
  max-age=86400` (ramp to 31536000 after a clean week — proxied WordPress
  responses pass upstream headers through untouched; a strict CSP stays
  Report-Only because the HTML shell's inline Cloudflare challenge script
  would break `script-src 'self'`).
- **Media worker:** the app fronts the worker directly (URL + `X-Site-Token`
  in the connection screen) — the two stay separate Velocity apps.
- **Second standalone host — NV oOS Complete Console**
  (`examples/nvoos-complete-velocity/`, Proposal 083, PR #7150): same Vite
  host recipe (alias + tsconfig paths + shims) **minus the connection
  screen** — fixed cookie-auth console that boots straight into spa-v2's
  `App` (mode admin). Deltas vs this section: `buildConsoleRuntime()`
  builds the runtime from the session (nonce via wp-admin scrape →
  `session/nonce` fallback, user via `wp/v2/users/me?context=edit`);
  assistant provisioning is "both" — `defaultAssistantId` pin + selector
  on; serve.mjs is the **Schedule Anything** variant (no runtime-env
  injection), port 4181; the sync workflow triggers on the app folder
  **and** `addons/pro/assets/spa-v2/**` (the console embeds spa-v2
  sources) and **prunes build-time files** (`src/`, configs, lockfile)
  from the mirror tree — bundle + serve scripts only. Cross-app SSO with
  the SA app is a deferred shared-domain-cookie follow-up. Independence
  note (2026-10-20): the console does **not** import
  `examples/nvoos-pro-spa-vite` — both apps alias
  `addons/pro/assets/spa-v2/src` directly, and the console wraps the chat
  surface in its own `ConsoleShell` + `ChatDock` (`src/console/`), so the
  chat surface ships inside the console bundle. Rebuilding the standalone
  app alone never changes the live console; every spa-v2 source fix must
  be compiled into **both** `addons/pro/assets/spa-v2/assets/dist/` and
  `examples/nvoos-complete-velocity/dist/` (see the rebuild map below).
- **Console nav gating (Proposal 084 Phase 0, shipped 2026-10-20):**
  the sidebar, dashboard quick-actions, and route guards all consume one
  catalogue — `examples/nvoos-complete-velocity/src/console/nav-features.tsx`
  (`CONSOLE_NAV_ITEMS` with per-item `capability?`/`feature?` gates,
  `isNavItemVisible()`, `RequireGate` redirect-to-`/` guard). Feature
  availability comes from the **server**, never URL guessing:
  `GET /mcp-ai/v1/features` (`includes/rest/class-wp-mcp-ai-rest-features-controller.php`,
  logged-in users) backed by `WP_MCP_AI_Feature_Flags`
  (`includes/class-wp-mcp-ai-feature-flags.php` — docs_hub, knowledge_graph,
  media_studio, document_editor, pro, okf; each filterable via
  `wp_mcp_ai_feature_available_<key>`). The PHP SPA config emits the same
  map as `runtime.features`, and `buildConsoleRuntime()` **empties the
  optional-surface endpoints** when a feature is off (pages then render
  their unavailable states). Console boot fetches the map via
  `fetchFeatureMap()` (runtime-config.ts) — **fail-open** by design (a
  failed fetch returns `{}`, never hides nav). Nav capabilities come from
  `wp/v2/users/me?context=edit`; the 7 admin-only items are gated on
  `manage_options` mirroring the PHP endpoint gating. Tools page is
  assistant-aware: `ToolsClient.list( assistantId? )` →
  `?assistant_id=…`, `useTools` refetches on the store's `assistantId`
  change, `ToolsPage` shows the scoped assistant in its subtitle (falls
  back to the server-resolved default assistant, then the full registry).
	  New console pages register their gates in `nav-features.tsx` — never
	  inline.
- **New endpoint key = four wiring points (learned 2026-10-24, PRs
  #7206/#7207).** Adding `endpoints.paperStore` needed (1) the PHP emitter
  `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php`, (2) the
  `ProSpaEndpoints` interface + `readProSpaConfig()` mapper in
  `api/config.ts`, and (3)+(4) **every app that builds its own endpoints
  map** — `examples/nvoos-complete-velocity/src/runtime-config.ts` AND
  `examples/nvoos-pro-spa-vite/src/runtime-config.ts`. Missing an app breaks
  THAT app's sync gate with TS2741 (missing required property) — #7207 was
  exactly that for the standalone app. When adding an endpoint key, grep
  `runtime-config.ts` across `examples/` and update them all.
- **Island PRs must update the console's endpoint VALUES, not just the type
  keys (learned 2026-10-25, console audit).** #7213 rewired
  `docsHub`/`mediaStudio`/`documentEditor` to REST bases + `?url` asset keys
  in the standalone app but left
  `examples/nvoos-complete-velocity/src/runtime-config.ts` carrying the
  **iframe-era values** (`docsHub: 'https://console.nvoos.cloud/docs-hub/'`,
  shortcode-page `mediaStudio`/`documentEditor`). Consequences: the console
  `tsc --noEmit` gate failed with TS2741 (nobody rebuilt, so nobody
  noticed), the sync workflow kept shipping the stale committed dist, and
  the LIVE console served the old iframe framing (theme padding + visible
  skip link inside the docs page). After any island/endpoint PR: diff both
  `buildEndpoints()` key sets AND values against the standalone app, fix the
  console, and rebuild `examples/nvoos-complete-velocity/dist/` in the SAME
  PR.
- **Console vitest vs `?url` island imports (learned 2026-10-25):** once
  `runtime-config.ts` imports the media-studio/document-editor bundles via
  `?url`, vitest dies with `Denied ID .../media-studio.js?url` — the imports
  live outside the app root. Fix: `server.fs.allow: [appRoot, repoRoot]` in
  the console's `vitest.config.ts` (mirror what `vite.config.ts` already
  allows for the dev server).
- **Magic-link emails must target the SPA origin, not the WP backend
  (learned 2026-10-25):** `magic_login_app_origin` (Authentication → Magic
  Link Sign-In) defaults to the WP `home_url()` — emails land on the
  WordPress site (a "Coming Soon" page on the console backend) instead of
  the console SPA. Set it to the SPA origin (`https://pro.nvoos.cloud` for
  the Complete Console) via wp-admin, the console's own `#/settings` (the
  field auto-surfaces from the schema), or `POST /settings/values`. The
  console already consumes `#/magic-login?token=` on boot (`main.tsx`
  `completeMagicLogin` → `POST mcp-ai/v1/magic-login/confirm` through the
  proxy) — the token handler needs no SPA work; the origin config is the
  whole fix.
- **Admin settings fields auto-surface in the console `#/settings`.**
  Fields added to a settings section (`get_fields()` + a subtab group in
  `includes/admin/sections/`) flow through
  `WP_MCP_AI_REST_Settings_Controller::build_schema_fields()`
  (SUPPORTED_FIELD_TYPES) into the console settings page with **zero SPA
  changes**. Feature code reads them via
  `WP_MCP_AI_Settings_Registry::get_setting()` (the grouped
  `wp_mcp_ai_settings` option) — not a standalone `get_option` — and may
  keep a standalone-option fallback for WP-CLI compat (the magic-login
  controller pattern, 2026-10-24).
- **Grid overflow containment recipe (learned 2026-10-24).** When a card or
  table blows past 100% width, the chain is track min-content → grid-item
  `min-width: auto`. Fix with `minmax(0, …)` tracks + `min-width: 0;
  overflow-x: auto` on the grid children (workflows page, Paper Store
  page). The shared DataTable's 640px `min-width` is the usual culprit
  inside narrow panes. Adjacent action buttons in a table cell get a gutter
  via `.nvoos-pro-spa-page__retry + .nvoos-pro-spa-page__retry {
  margin-left: 6px }` (Blueprints Install/Details, Approvals pair).
- **SSE mid-stream frame bridging (AI SDK v1.2.12 has NO `onData` callback;
  learned 2026-10-24, job_offload pass):** `chat-spa`'s `@ai-sdk/react@1.2.12`
  `useChat` exposes no data-stream callback, so mid-stream server events
  (e.g. the Phase 4b `job_offload` frame from a long-run offload) can't
  reach React through the SDK. The shipped pattern: `sse-adapter.ts`
  `translateFrame()` (already takes `assistantId`) dispatches a **window
  `CustomEvent`** — `window.dispatchEvent(new CustomEvent('nvoos:job_offload',
  { detail })`) — while also emitting the normal type-8 data annotation;
  `useChatSpoke.ts` subscribes with `addEventListener`, filters by
  `assistant_id`/`session_key`, toasts the status, and follows the job via
  `wpMcPaiJobBus.watchJob(jobId)` → `setMessages` to append the final
  assistant message. Reuse this bridge for any frame type the chat UI needs
  but the SDK can't deliver; type the globals (`JobBusShape`/`JobBusWindow`)
  on `window`. This is a spa-v2 source change, so the **two-bundle rule**
  applies — rebuild BOTH `addons/pro/assets/spa-v2/assets/dist/` and
  `examples/nvoos-complete-velocity/dist/`.

### Clean-install CI gate (the npm ci lockfile gotcha — PR #7171)

The standalone app's CI gate is `npm ci && npm run build`, and `npm ci`
installs **strictly from the committed lockfile**. When a dependency is
added to `package.json` (e.g. `@xyflow/react` for the Workflow Builder
canvas) without regenerating `package-lock.json`, a stale local
`node_modules` masks the break — everything passes in dev, then CI fails
with `TS2307: Cannot find module` (+ cascading TS7006 implicit-any from
the lost types). Rules:

- Any `package.json` dependency change in `examples/nvoos-pro-spa-vite`
  (or any `npm ci`-gated app) must ship with the regenerated
  `package-lock.json` (`npm install`).
- The standalone app mirrors spa-v2's dependency set — when spa-v2 gains a
  runtime dep consumed by shared source (`src/features/**` compiled through
  the Vite alias), add the same version to the standalone manifest in the
  same commit.
- Verify with the exact gate before pushing:
  `rm -rf node_modules && npm ci && npm run build`.

## Porting a plugin JS widget into spa-v2 (vendored-upstream pattern)

Canonical case study: the NV oOS Content Graph explorer ported to the
`#/knowledge-graph` page (PR #6998, 2026-10-10). Use when a plugin's
standalone JS widget (jQuery/Cytoscape/etc.) should render inside spa-v2
AND the standalone Vite app, without forking the widget.

### Layout

- Vendor the upstream files into `src/features/<feature>/upstream/` — never
  a folder named `vendor/` (it matches the user's global
  file_scan_exclusions and is invisible to file tools). Copy the CSS too,
  and add one `.d.ts` per JS module.
- Keep the upstream body **byte-identical**; apply only a documented
  wrapper delta, with the delta list in the file header so re-copying
  upstream is mechanical:
  1. IIFE → exported factory (`export function initX( $ ) { ... }`).
  2. Namespace every `$( document ).on( 'evt', ... )` binding
     (`evt.nvoos-cg`) so teardown can `$( document ).off( '.nvoos-cg' )`.
  3. Return `{ destroy }`: stop rAF loops, clear `setTimeout`s,
     `cy.destroy()` + null the instance, unbind namespaced listeners,
     remove body-appended elements.
  4. Add a `destroyed` latch reset at factory entry and checked at the top
     of the init choke point — an in-flight ajax completing after unmount
     must not resurrect the widget on a detached container.
- The React page is an **imperative island**: render the widget's exact
  markup (toolbar/container ids) in JSX, write the widget's config onto
  `window.*` in `useEffect` before calling the factory, and call
  `destroy()` in the cleanup (the React docs "Integrating with Other
  Libraries" contract). Config comes from the runtime
  (`readProSpaConfig()`) plus a plugin REST route — the graph case added
  `GET /graph/visual-config` server-side; never scrape the admin page.
- Heavy deps (jquery, cytoscape, fcose) go in BOTH
  `addons/pro/assets/spa-v2/package.json` and the standalone app's
  `package.json`, forced onto one tree with Vite directory aliases (a
  second cytoscape instance breaks extension registration). Register
  extensions once at module scope (`cytoscape.use( fcose )`) and hand the
  core to the widget via `window.cytoscape`.
- Lazy-load: route in `router.tsx` (React.lazy) + command-palette entry in
  `useBootstrap.ts`. The standalone Vite build splits the page into its own
  chunk (graph page: 213 KB gzip, loaded on visit only).

### Type/typecheck gotchas

- The standalone app's `tsc` typechecks spa-v2 sources THROUGH the import
  graph (its `runtime-config.ts` imports types from
  `@nvoos/pro-spa-v2/api/config` and `main.tsx` mounts spa-v2's App), so an
  ambient `declare module` shim spa-v2 needs (e.g. untyped
  `cytoscape-fcose`) must ALSO live in the app's own `src/shims/` to enter
  the app's TS program. Keep both declarations identical.
- **Every spa-v2 dependency must resolve in the app's TS program.** The
  mirror ships `addons/` without `node_modules`, and tsc resolves spa-v2's
  imports by walking UP from spa-v2's files — it never sees the app's
  `node_modules`. Typed packages need an entry in the app's tsconfig
  `paths` map (`examples/nvoos-pro-spa-vite/tsconfig.json`); untyped ones
  need the ambient shim (above). Monorepo CI stays green either way
  (spa-v2 resolves its own `node_modules` there), so a missing entry only
  fails in the mirror/Velocity build — TS2307 on spa-v2's import lines.
  Case study: the graph page's `jquery`/`cytoscape` imports broke the
  Velocity deploy (2026-10-11) and were fixed by mapping them to
  `@types/jquery`/`@types/cytoscape` (PR #7000).
- Each vendored `.js` needs a sibling `.d.ts` — the page's imports resolve
  to it (no `allowJs` needed). Global Window augmentation goes in the
  side-effect module's d.ts.

### Testing gotchas (vitest)

- `vi.mock` factories are hoisted — share state via
  `vi.hoisted( () => ({ initMock: vi.fn( ... ) }) )`.
- Mock paths are relative to the TEST file but must match the PAGE's import
  specifiers: from `__tests__/` mock `'../upstream/content-graph-admin'`,
  not `'./upstream/...'` — the wrong path silently leaves the REAL module
  active (symptom: `$ is not a function` from the real jQuery IIFE).
- **Depth trap (Proposal 087):** the mock specifier must resolve to the SAME
  absolute file as the page's own import. `vi.mock( '../../utils/x' )` from
  `src/features/foo/__tests__/` resolves to `src/features/utils/x` — a
  NONEXISTENT file — so the mock silently never applies and the real module
  runs (symptom: `Number of calls: 0` on your `vi.fn()` mocks while the
  page clearly works; no resolution error is raised). Count the levels from
  the TEST file's directory: `features/foo/__tests__/` → `../../../utils/x`.
- Components that call router hooks (`useNavigate`, `useLocation` — e.g.
  ContentArea after Proposal 087) throw outside a router; wrap their test
  renders in `<MemoryRouter>` via a `renderArea( ui )` helper instead of
  scattering wrappers.
- **Windows rolldown binding:** vitest on a Windows worktree can die at
  startup with `Cannot find native binding` /
  `Cannot find module '@rolldown/binding-win32-x64-msvc'` (npm bug #4828
  skips optional deps). `npm install` in the addon repairs it — check the
  lockfile stayed untouched before committing.
- jsdom cannot run Cytoscape's canvas — mock the vendored factory + deps;
  test the config hand-off (`window.nvoosContentGraphAdmin` shape) and the
  destroy-on-unmount contract, not the vendored internals.
- `eslint-disable-line` for `tabIndex` must sit INSIDE the JSX attribute
  expression
  (`tabIndex={ /* eslint-disable-line jsx-a11y/no-noninteractive-tabindex */ 0 }`)
  — a disable comment on the line before the tag targets the wrong line
  (the rule reports on the attribute line, not the tag line).

### Bundle-size trade-off

- The plugin's esbuild build uses `splitting: false` (IIFE) — lazy pages are
  INLINED into `pro-spa.js` (the graph page added ~630 KB minified:
  cytoscape + fcose + jquery). Accept it or propose a separate entry point;
  the standalone app gets real chunking. Call the growth out in the PR body.

### jQuery-pathed requests bypass the fetch wrapper (PR #7002)

- The vendored explorer loads `/nodes`, `/edges`, and node details via
  **jQuery `$.ajax`**, which never sees the app's global `window.fetch`
  wrapper — in bearer/guest/wp-login modes those XHRs went out with no
  credential header (and an empty `X-WP-Nonce`) and 401'd behind the
  explorer's misleading "Failed to load graph data". The standalone app's
  `fetch-wrapper.ts` now mirrors its header logic onto jQuery via an
  `ajaxPrefilter`: bearer/basic/guest headers attached, the empty nonce
  dropped in token modes, the media-worker `X-Site-Token` forwarded, live
  option reads (reconnect-safe), installed once (jQuery has no
  prefilter-removal API), never throwing. Any widget using `$.ajax` against
  the backend needs the same prefilter — the fetch wrapper alone is not
  enough.

## Island embeds in the Pro SPA (Proposal 087 — self-contained React apps)

Canonical case study: PR #7213 (merged 2026-10-24) replaced the sandboxed
iframe framing of the Docs Hub / Media Studio / Document Editor with native
imperative islands — the same shell-owns-config/auth contract as the
knowledge-graph page, but for apps that ship their OWN React tree. Proposals:
`docs/project/proposals/087-docs-hub-native-island-proposal.md` + the
implementation-plan twin.

### The three island variants

| Variant | Apps | Mount | Asset delivery |
|---|---|---|---|
| **Vendored inline** | Docs Hub | static `import './upstream/docs-hub'` (IIFE executes at bundle load) | Inlined into `pro-spa.js` (~660 KB minified) |
| **Lazy script asset** | Media Studio, Document Editor | `utils/island-assets.ts` `loadIslandScript()` (once per session) + `loadIslandStyle()` | `*Asset` / `*Css` endpoints (PHP `plugins_url`) or Vite `?url` imports in the standalone app |
| **REST adapter** | OKF knowledge (docs page second source) | same vendored Docs Hub island, `apiUrl` remounted per source | `mcp-ai-pro/v1/okf/docs` serves the docs-hub manifest/pages/search shape |

### Island API contract (the upstream apps, e.g. `addons/docs-hub/src/index.tsx`)

1. Export `mountX( container: HTMLElement ): { unmount(): void }` and assign
   it explicitly on `globalThis` (`window.NVoosDocsHub` /
   `NVoosMediaStudio` / `NVoosDocumentEditor`) — REMOVE the esbuild
   `globalName` option (an inlined IIFE's `var` never reaches `window`; the
   explicit assignment does, and keeps the vendored file byte-identical).
2. Add `defer_mount?: boolean` to the app's config type; the auto-mount DOM
   scan returns early when it's set (the host mounts explicitly). Inject
   the app's config global BEFORE the script loads so the scan sees the flag.
3. Each unmount must teardown the React root (`root.unmount()`) — the page
   calls it in its effect cleanup and deletes the injected global.

### Hash ownership — islands must never write `window.location.hash`

- Docs Hub uses a HashRouter internally → new MemoryRouter mode
  (`config.router: 'memory'` + `DocRouterModeContext`); all internal links
  normalized to path form (`to="/slug"`, not `/#/slug`), raw `#/…` content
  anchors intercepted to `navigate()`, fragment scroll reads
  `useLocation().hash`, skip link prevents default. Hash mode remains the
  wp.org shortcode default.
- Media Studio / Document Editor have no router, but their skip links
  (`a.nvoos-skip-link[href^="#"]`) would still touch the hash — the pages
  install `useIslandSkipLinkGuard()` (capture-phase listener: preventDefault
  + focus the target).

### Auth — postMessage bridge is gone

- The `utils/auth-bridge.ts` `postAuthToChild()` was removed; pages inject
  the headless credential straight into the island config global as an
  `authorization` key (the islands already honoured that key for same-origin
  hosts), origin-checked: only when `new URL(apiBase).origin ===
  credential.siteOrigin`. Cookie mode passes the `wp_rest` nonce only.
  `getAuthCredential()` + the `__NVOOS_AUTH__` slot remain (the standalone
  app's `fetch-wrapper.ts` owns the slot).

### Theming islands — host token bridge (2026-10-25)

Industry pattern for embedded surfaces: **"map only design tokens"** — the
host rebrands the island by remapping the island's own semantic variables at
the boundary; it never restyles internals and never ports its design system
in (the island stays standalone + byte-identical). The docs-hub bridge lives
in `spa-v2/src/features/docs-hub/docs-hub.css`:

```css
/* Light: NDS addon (when active) → SPA tokens → island default */
.nvoos-pro-spa-docs-hub__island .nvoos-docs-hub-root {
	--dh-accent-primary: var(--nds-color-accent, var(--nvoos-primary, #2563eb));
	/* ... text, borders, surfaces, font-family, radius ... */
}
/* Dark: the island's own palette restored VERBATIM (0-3-0 beats the light
   bridge) — the island toggles dark mode independently of the host. */
.nvoos-pro-spa-docs-hub__island .nvoos-docs-hub-root[data-theme='dark'],
.nvoos-pro-spa-docs-hub__island .nvoos-docs-hub-root.dh-dark { /* ... */ }
```

Cascade rules learned: the bridge must be declared ON `.nvoos-docs-hub-root`
(child-root declarations always beat container-level inheritance), the light
bridge needs 0-2-0 (island light tokens are 0-1-0), and the dark counterpart
needs 0-3-0 (island dark tokens are 0-2-0 — a tie with the bridge, and the
wrapper CSS imports AFTER the upstream file, so a tie would clobber the
island's dark mode). Never let dark mode chase host tokens. Velocity-hosted
apps never resolve `--nds-*` (the design-system addon generates them on WP
pages only) — the fallback chain handles that by design.

### Endpoints + mirror sync

- `docsHub` / `mediaStudio` / `documentEditor` now carry **REST bases**
  (e.g. `nvoos-media-studio/v1`), class/feature-flag-gated like
  `contentGraph`; the `wp_mcp_ai_pro_spa_*_url` filters retained but now
  take REST bases, not page/URLs.
- The standalone app imports the island bundles via Vite `?url`
  (`import x from '../../../addons/media-studio/assets/dist/media-studio.js?url'`).
  The mirror (`sync-nvoos-pro-spa-standalone.yml`) must copy those dist
  folders too — UNRESOLVED_IMPORT in the mirror build is the symptom when it
  doesn't (the monorepo build stays green). Both dist folders are now in the
  workflow's `paths` trigger as well.

### Sync/release discipline

- docs-hub: after ANY `addons/docs-hub/src` change, `npm run build` (dist is
  tracked), copy `assets/dist/docs-hub.{js,css}` into
  `spa-v2/src/features/docs-hub/upstream/` (byte-identical — verify with
  `cmp`), rebuild `pro-spa.js`. The upstream apps' vitest suites run in CI
  (docs-hub vitest needs `npm install` locally on Windows — see testing
  gotchas below).
- media-studio / document-editor: `npm run build` in the addon after src
  changes; the dists are consumed directly (no vendored copy).
- Bundle-size rule from the graph page still applies: media/document are
  NOT inlined (2.7 MB + 1.7 MB) — they load lazily from their asset URLs.

## Component rules — headless only

- **Adopt Radix primitives** (`@radix-ui/react-*`, MIT) wrapped per-addon in
  `src/components/ui/` with the **shadcn methodology**: copy the wrapper code
  into the repo (we own and ship it), CVA for variants, `clsx`
  (+ `tailwind-merge` when Tailwind is in play) via a `cn()` helper in
  `src/lib/utils.ts`. Use `sonner` for toasts, `react-hook-form` + `zod`
  (`^3.25`, `@hookform/resolvers` `^3`) for forms, `@tanstack/react-table`
  for tables, `@dnd-kit` for drag-and-drop.
- **Reject pre-styled frameworks** (MUI, Ant Design, Chakra, PrimeReact,
  HeroUI): bundle weight, wp-admin visual clash, second theming system.
  Advanced tables come from TanStack Table, not PrimeReact.
- **Consume NV oOS Design System tokens**: every color/size should reference
  `var(--nds-*, <wp-admin fallback>)` so the design-system addon rebrands the
  UI when active. Verified token names include `--nds-color-accent`,
  `--nds-color-accent-hover`, `--nds-color-border`, `--nds-color-surface`,
  `--nds-color-text-primary`, `--nds-font-family`, `--nds-space-{xs,sm,md}`,
  `--nds-transition-{fast,normal}`. In Tailwind v4 map them with
  `@theme inline { --color-primary-500: var(--nds-color-accent, #3b82f6); }`
  (the `inline` keyword emits the raw `var()`). Pattern A keeps plain CSS with
  local aliases (`--ts-*`) so the dark NDS surface default never leaks into
  the light wp-admin theme.
- Licenses: MIT / Apache-2.0 / BSD / ISC only; bundle everything (no CDN);
  update `THIRD_PARTY_NOTICES.md` (+ root `CREDITS.md`) and the README
  Credits section; run `gh-advisory-database` for any new dependency.

## Tailwind v4 (Pattern B) notes

- `@tailwindcss/vite` plugin (or `@tailwindcss/postcss` for esbuild); delete
  `tailwind.config.js` — config is CSS-first (`@import "tailwindcss";` +
  `@theme`/`@theme inline` blocks in `global.css`).
- Drop autoprefixer (Lightning CSS handles prefixing).
- Never reference undefined theme tokens in utility classes (e.g.
  `ring-ring`); use real palette keys or define them.
- **Tracked-dist feedback loop (found 2026-10-20, PR #7148):** Tailwind's
  automatic source detection includes the **git-tracked `assets/dist`** —
  the prebuilt bundle the repo commits. Each build then harvests phantom
  class candidates from the previous build's minified JS, so the emitted
  CSS differs run-to-run (observed: 35.14 → 35.49 → 34.98 KB across three
  unchanged-source builds) and dist hashes never settle — the
  byte-identical-rebuild check below fails forever. **Fix:** exclude the
  output dir from the scan in `src/styles/global.css` (path is relative to
  the CSS file):
  ```css
  /* Exclude the git-tracked build output from Tailwind's automatic source
     detection — otherwise each build scans the previous build's minified
     JS and dist hashes never settle. */
  @source not "../../assets/dist";
  ```
  After the fix, consecutive builds are byte-identical and the CSS shrank
  ~2 KB (the phantom classes). Any Tailwind-v4 addon that commits its
  build output needs the same exclusion; add it at scaffold time.
- **Determinism gate:** after any build-affecting change (deps, CSS,
  config), build twice and byte-compare — deterministic bundles produce no
  diff on the second run. If hashes rotate between runs, suspect a scan or
  ordering feedback loop, not tooling noise.

## Upgrading a Pattern B SPA to React 19 (checklist, case study PR #7148)

Works for schedule-anything-spa and any future Vite product SPA when the
Pattern-B React 18.3 pin is lifted (see rule 1 — only valid while the SPA
is standalone, never embedded inline in wp-admin).

1. **Migration-surface greps first** — the React 19 removals/type changes
   that break code: `React.FC` (implicit children gone in @types 19),
   global `JSX.Element`, `forwardRef`, `defaultProps`, `PropTypes`,
   `ReactDOM.render`, `findDOMNode`, argument-less `useRef()`. The SA SPA
   had zero matches, and the upgrade was clean.
2. **Peer-dep tolerance via the lockfile** — verify what `npm install`
   actually resolved: `recharts` needs ≥2.15 (peer includes ^19);
   `@axe-core/react` 4.13 has **no react peer**; react-transition-group is
   `>=16.6`. If a dev-only tool hard-pins React 18 peers, `--legacy-peer-deps`
   or an override beats downgrading.
3. **Pins** — `react`/`react-dom` exact `19.1.0` (matches spa-v2 + the
   standalone app); `@types/react`/`@types/react-dom` `^19.1.4`.
4. **Gates** — `typecheck` (the real risk: @types 19 strictness), a11y
   lint, vitest, **build ×2 determinism**, main-chunk budget (SA grew
   93 → 105.95 KB gzip: part React 19, part Radix minors from the lock
   refresh — still under the 150 KB gate; call the growth out in the PR).
5. **Document the deviation** — addon README + this skill's matrix row, so
   future agents don't "fix" it back to 18.
6. **Dist-only merge conflicts are the norm afterwards** — two branches
   rebuilding the same source rotate chunk hashes. Never hand-merge
   minified bundles: merge the sources, rebuild (`npm run build`), re-sync
   (`npm run sync:dist` in the velocity app), `git add` both dist trees.
   Dry-run mergeability without touching the worktree:
   `git merge-tree --write-tree HEAD origin/<base>` (exit 0 = clean).

## @xyflow/react v12 (upgraded from reactflow 11)

- **No default export** — `import { ReactFlow, ... } from '@xyflow/react'`.
- Custom node data typing: `type ToolNodeType = Node<ToolNodeData, 'toolNode'>`
  (data must be a type alias, not an interface, to satisfy
  `Record<string, unknown>`); components take `NodeProps<ToolNodeType>`.
- `NodeProps` requires the full node descriptor (id, type, dragging, zIndex,
  isConnectable, positionAbsoluteX/Y…) — tests need a typed props factory
  (see `addons/schedule-anything-spa/src/components/builder/__tests__/nodes.test.tsx`).
- `instance.project(...)` is gone — use `instance.screenToFlowPosition({x, y})`.
- `<Handle/>` needs `ReactFlowProvider` ancestry, including in tests.
- Heavy builder routes should be `React.lazy`-split (see App.tsx).

## SPA test stack (both addons)

Stack: **vitest + jsdom + @testing-library/react/user-event/jest-dom**. Setup
file (`src/test-setup.ts`) must polyfill for Radix's Popper layer:
`ResizeObserver`, `Element.prototype.scrollIntoView`,
`hasPointerCapture`/`setPointerCapture`/`releasePointerCapture`, and
`window.matchMedia`. Pattern A additionally needs the `window.NVOOS_TOOLKIT_SHELL`
bootstrap fixture and heavy-view mocks (see `api.test.tsx`).

### Known jsdom gotchas (all encountered in PR #6952 — do not rediscover)

1. **Radix Select inside a native `<form>` resets its value under jsdom.**
   Radix's hidden `BubbleSelect` (native form integration) re-dispatches a
   `change` event whose `target.value` is bogus in jsdom, resetting the
   controlled field. Real browsers filter the duplicate upstream. Fix in
   tests: integration-test the real Radix Select **form-free** (bare wrapper
   asserting `onValueChange`), and in form tests use a documented
   native-select stand-in via `vi.mock` (see
   `addons/toolkit-shell/src/components/__tests__/FormView.test.tsx`). In
   production code, defensively ignore `undefined`/`null` in `onValueChange`.
2. **RHF + zodResolver identity churn resets controlled Radix inputs.** RHF
   re-validates when the resolver identity changes; a resolver built inline
   from a runtime schema re-runs every render. Always
   `useMemo`-stabilize the schema, `zodResolver(schema)`, and `defaultValues`.
3. **zod coerces empty numeric fields to `0` on submit.** Use
   `z.preprocess(emptyToUndefined, z.coerce.number().optional())` so `''`
   stays unset; required numerics reject empty with the field's message.
4. **dnd-kit sortable attributes put `role="button"` on the wrapper**, which
   nests a button inside a button when the card itself is a button. Use the
   drag-handle pattern: separate handle button carrying `{...attributes}
   {...listeners}`, content button for the click action.
5. **jsdom pointer events lack `pointerType`** — Radix pointer paths are
   unreliable; prefer `user.click` on triggers/options (works form-free) or
   `fireEvent` for isolated cases.

## Release bookkeeping

- Pattern A: bump **all three** versions together (plugin header `Version:`,
  `NVOOS_*_VERSION` define, `package.json`) whenever the bundle changes —
  never hand-edit `assets/dist/`, always `npm run build`.
- Budgets: keep Pattern-A shells ≲170 KB gzip total; split heavy routes in
  Pattern B (`React.lazy`) — the Schedule Anything main chunk is ~93 KB gzip
  with Builder at ~63 KB.
- Lint gates: `npm run lint:a11y` (`eslint-plugin-jsx-a11y`, `--max-warnings 0`)
  must pass; typecheck with `npm run typecheck`; tests with `npm test`.
- PRs: branch `add/<slug>` from `alpha-working`, PR back to `alpha-working`,
  commit built artifacts and lockfiles.
- Shared worktree caution: another agent or a sync process can revert a file
  mid-session (seen with `workflowHelpers.ts`, 2026-10-09). After edits,
  verify on-disk state with a marker grep before rebuilding/committing; if a
  fuzzy edit double-applies, rewrite the file whole with `write_file`.
- **Shared-checkout PR hygiene (foreign WIP):** a shared checkout can carry
  another agent's UNCOMMITTED WIP (symptom: `git status` shows modified
  files you never touched). Stage explicit paths only; run
  `git diff <path>` before `git add` and check every hunk is yours — a
  dirty file you edit for your own reasons will sweep their hunks into your
  commit (happened with `vite.config.ts` + `README.md` on 2026-10-10). If
  it already happened: back up the working file, restore the base version,
  re-apply ONLY your hunks, commit, push; then move the foreign WIP to its
  own branch + PR with `git stash push -- <paths>` →
  `git switch -c <branch> origin/alpha-working` → `git stash pop`.
  CAUTION: a stash is a diff against the branch where it was made — if the
  target branch already contains part of the stashed state, the pop can
  silently drop hunks (verify each file's diff after the pop; re-apply by
  hand when missing). When two PRs touch the same file on adjacent hunks,
  note the conflict resolution in the PR bodies.

## Full JS rebuild map — shipping fresh artifacts

When the ask is "rebuild all JS so the plugin is packaged up to date", the
scope is the whole tree, not one addon. Verified inventory (2026-10-09):

| Area | Command | Notes |
|---|---|---|
| Core CSS/JS | `npm run build:css && npm run build:js` | root esbuild configs |
| Pro JS | `npm run build:js:pro` | **needs `addons/pro/node_modules`** — worktrees often lack it; run `(cd addons/pro && npm install --legacy-peer-deps --omit=optional --no-optional)` first. Bundles `generate-{pdf,word,excel}` + remotion into `addons/pro/bin/` |
| docs-hub | `npm run build:docs-hub` | prebuild runs `npm ci` if `node_modules` missing |
| workflow + TMA | `npm run build:workflow && npm run build:tma` | wp-scripts webpack → `addons/pro/build/` |
| Pattern A SPAs | `toolkit-shell`, `chat-spa`, `comic-reader`, `librechat`, `media-studio`, `funiq-bridge`, `canvas-toolkit`, `document-editor`, `pro/assets/spa-v2` — esbuild `npm run build`; `page-agent` uses `node esbuild.config.js` (dev output, no `--prod`) | `npm ci` per addon when `node_modules` missing (lockfile is committed) |
| Pattern B SPA | `schedule-anything-spa` — `npm run build` (`tsc && vite build`) | |
| Pattern C SPA | `saas-controller` — `npm run build` (worker esbuild + drift-manifest stamp + wp-scripts admin) | |
| Legacy pro SPA | `addons/pro/assets/spa` — `npm run build` (wp-scripts webpack → `dist/`) | |
| spa-v2 plugin bundle | `addons/pro/assets/spa-v2` — `npm run build` | esbuild → **tracked** `assets/dist/pro-spa.js` + `.css` (plugin-served admin SPA) |
| Complete Console (Velocity) | `examples/nvoos-complete-velocity` — `npm run build` (`tsc --noEmit && vite build`); `npm run sync:dist` = typecheck+build+closure gate | Vite → **tracked** `dist/`; Velocity serves it prebuilt, no build step on the server |
| npm packages | `packages/nvoos-*` — each has `npm run build` (`adapt-for-npm.cjs`); `nvoos-mcp-bridge` has none by design | loop with a small node script; dist folders are git-tracked |
| content-graph vendor | `plugins/nvoos-content-graph` — `npm install` then `node scripts/copy-vendor.js` | |

Skips: `algorave` / `fantasy-football` (no-op echo builds), `mcp-gateway` /
`mcp-wordpress-gateway` / `media-worker` (run from source, no build step),
`tenant-router` (wrangler-only), `canvas` (native linux-x64 binary — Docker CI
workflow only; use `--skip-canvas` on dev machines).

Verification: deterministic bundles rebuild **byte-identical** — those files
don't show in `git status`; only stale artifacts appear as modified. Check
mtimes to confirm a build actually ran. Full run: ~15 min, mostly `npm ci`
for saas-controller (2k+ deps). npm on dev machines blocks dependency install
scripts (esbuild postinstall etc.) — harmless, platform binaries ship via
optional deps.

**Two-bundle rule for spa-v2 (learned 2026-10-20, PR #7158/#7159):** the
auto `build: update compiled assets [skip ci]` commit covers neither of
the two spa-v2 trees, so a spa-v2 change reaches production only when
BOTH `addons/pro/assets/spa-v2/assets/dist/` and
`examples/nvoos-complete-velocity/dist/` are rebuilt and merged.
Squash-merging a multi-commit rebuild PR can silently drop the second
bundle: #7158's squash only carried the plugin bundle, the console
`dist/` rebuild was left unmerged, and the live Complete Console showed
none of the fixes until #7159. After any rebuild-PR merge, verify each
rebuild commit is an ancestor of the target branch
(`git merge-base --is-ancestor <sha> origin/alpha-working`).

**The sync workflow does NOT rebuild the console dist (learned
2026-10-24).** `sync-nvoos-complete-velocity.yml` assembles the mirror from
the **committed** `examples/nvoos-complete-velocity/dist/` (asset-closure
check, npm-script no-ops, boot smoke) — it never runs Vite. A
console-source change merged without `npm run sync:dist` + a dist commit
ships the stale bundle to the mirror (case: #7206 merged carrying the
#7205-era dist; fixed by the #7208 rebuild commit). Console source changes
and their dist rebuild belong in the same PR.

### Rebuild discipline — staleness, vendor, drift (learned 2026-10-22)

**Lockfile movement stales tracked bundles.** Any commit that changes
`package-lock.json` without a rebuild (advisory patches #7175/#7106, dep
bumps) leaves the bundles in git embedding the old dependency graph.
Signature: `git status` shows modified `*.bundle.js`/`dist/` files whose
sources are clean, and `git log --oneline origin/alpha-working --
package-lock.json` is newer than `git log --oneline -1 -- <bundle>`.
Prove it is dependency-internal churn with a marker grep — committed vs
fresh `grep -c EXPANSION_MAX_DEPTH addons/pro/bin/generate-excel.bundle.js`
went 2 → 0 after the brace-expansion override moved. Fix: run the full map
above and PR the artifact diffs. Verified 2026-10-22 (PR #7182): 16 files
— chat-spa, librechat, legacy spa, the three `generate-*` worker bundles,
2 of 4 TMA entries, saas drift-manifest, root chat/http-client assets;
spa-v2 + complete-velocity came out byte-identical (rebuilt same-day).

**Vendor is tracked in production state — never commit composer regen.**
`composer install` rewrites `vendor/composer/autoload_*.php` +
`installed.{json,php}` to the dev flavor; committing them breaks cloning
the repo as a production plugin (PR #7179 was reverted for exactly this;
#7180 re-applied the work without vendor). Keep the regen as local-only
dirt — it is what makes `vendor/bin/phpunit` runnable. Pre-commit gate
before ANY commit: `git diff --cached --name-only | grep -E '^vendor/'`
must print nothing.

**node_modules drift vs lockfile.** `npm ci --dry-run` reveals drift in a
shared worktree. Before rebuilding, check whether drift touches the bundle
chains (`npm ls <pkg>` vs the lockfile entries) — a rebuild with drifted
`node_modules` bakes non-lockfile versions into committed bundles. Bare
`npm ci` in a shared worktree is risky: npm 12 blocks install scripts by
default (esbuild postinstall among them) and `.npmrc` sets `omit=optional`;
if an addon build then fails for a missing esbuild binary, remove the
addon's `node_modules/esbuild` so resolution falls back to the working
root copy.

**Standalone addon deps are not hoisted (no workspaces).** `chat-spa`'s
`@ai-sdk/react@1.2.12` exists only in its own committed
`package-lock.json`; a build failing with "Could not resolve <pkg>" means
the addon's `node_modules` is missing — run `npm ci` inside the addon dir
(not the root), then rebuild.

**Reverting a bad merge on a shared branch.** When a rebuild (or any)
squash lands with unintended paths and other commits have arrived on top
of `alpha-working`, force-push is off the table. Revert in a throwaway
worktree: `git worktree add <tmp>` → `git revert --no-edit <merge-sha>` →
push → `git worktree remove`. Drive it with `git -C <tmp> …` because the
temp path is outside the terminal tool's allowed project roots. Then
rewrite the feature branch without the offending paths and re-PR.

**PR merge discipline.** Create the PR and stop — merge only when the
user explicitly asks for the merge. Never self-merge a PR off the back of
an inferred intent.

## TS-shadows-JS resolution gotcha (wp-scripts)

`@wordpress/scripts` webpack resolves extensionless imports with TypeScript
first, so a `foo.ts` sibling **shadows** `foo.js` — the `.ts` edition is what
gets bundled, silently. The 2026-10 TS upgrade created `.ts` editions of the
workflow-builder utils but left the `.js` originals in place; the incomplete
`.ts` editions were bundled instead. Signature (a runtime bug, not just
noise — missing exports resolve to `undefined`, e.g. dead Export/Import
buttons):

```
WARNING in ./src/workflow-builder/components/WorkflowBuilder.jsx 276:4-18
export 'exportWorkflow' (imported as 'exportWorkflow') was not found in '../utils/workflowHelpers' (possible exports: generateNodeId, validateWorkflow)
```

When you see this warning: grep the resolved module's directory. If both
`foo.js` and `foo.ts` exist, webpack is bundling the `.ts` — port the missing
logic into the `.ts` file (it is canonical), then delete the dead `.js`
duplicate and update README/docs references. Reconciliation precedent
(2026-10-09, PR #6979): ported `exportWorkflow`/`importWorkflow`, the
`validateWorkflow` node-config checks + DFS cycle detection, and
`animated: true` template edges (`WorkflowEdge.animated?: boolean` added to
`src/shared/types.ts`); deleted five dead `.js` utils; `workflowExecutor.js`
kept — it has no TS counterpart and is canonical as-is. The other pairs
(`executionHistory`, `workflowHistory`, `workflowVersioning`) were already
functionally equivalent.

## References

- Proposal 060 docs (linked above) — decisions, file manifest, verification matrix
- `docs/addons/toolkit-spa-blueprint.md` — canonical addon pattern
- `addons/toolkit-shell/src/components/ui/` and `addons/schedule-anything-spa/src/components/ui/` — reference UI kits
- `addons/saas-controller/README.md` + `THIRD_PARTY_NOTICES.md` — Pattern C reference
- `mcp-ai-wpoos-test-suite` skill — PHPUnit side of testing (disjoint scope)
- `mcp-ai-schedule-anything-ops` skill — Schedule Anything SaaS ops: the
  presets/toolkits REST contract, the WP 6.9 nonce cookie-auth pitfall, the
  sa-e2e harness, and the Cloudways Velocity deploy/mirror-sync loop
- `.github/agents/toolkit-spa-maintainer.agent.md` — addon-scoped writer agent
