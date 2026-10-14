# Proposal 077 — Unify the NV oOS SPA Hub into a Single-Shell Surface

**Status:** Draft for review
**Date:** 2026-10-14
**Author:** Zed agent (DeepSeek V4 Pro)
**Related:** PR #7063 (`spa-surfaces` endpoint + dictate multi-surface hub),
PR #6998 (content-graph SPA page), Proposal 060 (SPA UI stack), Proposal 065
(content-graph npm packaging), skill `mcp-ai-wpoos-content-graph-spa`, skill
`mcp-ai-wpoos-spa-ui`, skill `mcp-ai-wpoos-dictation-app`.

---

## 1. Problem statement

The dictate desktop app's "NV oOS" tab (`examples/nvoos-dictate/src/views/Assistant.tsx`)
discovers hosted SPA surfaces from `GET /wp-json/mcp-ai-pro/v1/spa-surfaces`
(served by `WP_MCP_AI_Pro_SPA_Surfaces_Controller`) and renders one sub-tab per
surface inside a single sandboxed `<iframe>`.

Today the surfaces split into two disjoint navigation models:

| Surface class | Examples | URL resolution | Tab-click behavior |
|---|---|---|---|
| **Route surface** | `content-graph` (`#/knowledge-graph`) | hosted Pro SPA base + hash fragment | hash-only change inside one SPA — **no reload** |
| **Home surface** | `assistant` (the chat/assistant SPA) | `$site_url` (hosted Pro SPA root) | navigates the iframe to the SPA root — **reloads when returned to** |
| **Page surfaces** | `media-studio`, `document-editor`, `toolkit-shell`, `docs-hub`, `canvas-toolkit`, `cloudways-dashboard`, `comic-reader`, `librechat`, `page-agent`, `algorave` | `find_page_with_shortcode()` → each addon's **own full WordPress page** | iframe `src` → a different page → **full reload**, and each page wraps its own theme/sidebar |

Two concrete pain points:

1. **The other SPAs reload and render their own chrome.** `media-studio`,
   `document-editor`, `toolkit-shell`, etc. are standalone React apps loaded as
   their own WordPress pages. Clicking their sub-tab swaps the iframe `src` to a
   new page, forcing a full reload and (in a hosted theme) a surrounding
   header/sidebar — unlike `content-graph`, which lives *inside* the hosted Pro
   SPA as a hash route and never reloads nor carries the chat sidebar.

2. **There is no stable "Home".** The chat/assistant surface is just another
   entry (`assistant`, first in the list, resolved to `$site_url`). Returning to
   it re-navigates the iframe and can reload, and it can appear alongside the
   hosted Pro SPA's own internal left sidebar (the `ChatSidebar`) in a way the
   user finds inconsistent.

### Goal

Make every surface behave like `content-graph`: a **single hosted Pro SPA
shell** (`spa-v2`) with internal hash routes, a stable **Home/Chat** as its
root route, no reload on tab switch, and no per-addon chrome. Ship it in two
tracks so the low-risk reload fix lands independently of the larger per-addon
port work.

---

## 2. Research & industry standards

Findings below are grounded in the following sources reviewed this session
(web search, 2026-10-14):

- freeCodeCamp — *How Microfrontends Work: From iframes to Module Federation*
- DEV Community — *Building True Micro-Frontends: Beyond iFrames with Module Federation*
- sphereinc.com — *Micro Frontends: Module Federation Approach*
- micro-frontend-architecture.com — *Webpack & Vite Module Federation Implementation* + *Sharing Singletons Across Webpack and Vite Remotes*
- module-federation/vite (GitHub) — native browser import maps for shared deps
- Stack Overflow #79051065, #72460553, #8318264 — iframe state preservation across tabs
- Reddit r/react — *Keeping Iframes Alive During Route Changes*
- didit.me, romaincoupey.com, MDN — iframe sandbox / CSP / postMessage security
- DEV/Tech Verse — `HashRouter` + `React.lazy` + `Suspense` route-level code splitting

### 2.1 iframe state preservation is a solved problem

The canonical, repeatedly confirmed answer to "keep an embedded app from
reloading when I switch tabs" is: **render every iframe once and toggle
visibility with CSS — never swap `src` and never unmount/re-mount.** Moving an
iframe in the DOM, changing its `src`, or unmounting it all destroy its
document + JS state and force a reload. Keep-alive via `display:none` /
`visibility:hidden` / z-index stacking preserves state and is the recommended
approach across React, Svelte, and vanilla discussions.

**Implication:** Track A (below) should mount one iframe per surface and toggle
the active one, rather than keeping a single iframe whose `src` is mutated.

### 2.2 Single shell + internal routes is the standard SPA composition pattern

The codebase already uses the industry-canonical pattern and should extend it
rather than adopt a new one:

- `addons/pro/assets/spa-v2/src/router.tsx` is a `HashRouter` with
  `React.lazy` + `Suspense` route-level code splitting (chat/settings/tools/
  assistants/workflows/analytics/knowledge-graph). This is exactly the
  "single-page shell, lazy feature routes, hash routing for WordPress-admin
  compatibility" pattern the sources recommend for an admin console.
- The `content-graph` port (PR #6998) is the canonical **vendored-upstream
  imperative island** pattern: copy the standalone widget into
  `spa-v2/src/features/<x>/upstream/` (byte-identical body + documented wrapper
  delta), render its exact markup in JSX, hand config via `window.*` in
  `useEffect`, call `destroy()` on unmount, and lazy-load the route.

**Implication:** Track B should port each standalone SPA into `spa-v2` as a
lazy `#/...` route using this exact, already-proven recipe — *not* introduce
Module Federation.

### 2.3 Why not Module Federation / native micro-frontends

Module Federation (Webpack `@module-federation/*` or `vite-plugin-federation`)
is the "load remote JS into one DOM" answer the sources describe — but the
codebase has already rejected that direction for cross-addon composition, and
the reasons still hold:

- The dictate skill states vendoring spa-v2 into a second React app "collides
  with React-version/dependency/CSP/network-model conflicts." The same
  dual-React hazard (Pattern A's explicit guard) applies when multiple addons
  each bundle their own React 19 tree.
- Federation requires a consistent host/remote build tool, shared-singleton
  wiring for `react`/`react-dom` (one `default` share scope, one React entry),
  and runtime `remoteEntry` resolution. The NV oOS addons are intentionally
  independent esbuild IIFE bundles (Pattern A) with **bundled React** — mixing
  them into one DOM without a shared React would corrupt hooks state.

The correct, lowest-risk composition for *this* codebase is: **lazy routes in
the single existing shell** (Track B), with iframes retained only as the
cross-*app* isolation boundary the dictate desktop app needs (the Tauri webview
embedding the hosted Pro SPA — Track A / current behavior).

### 2.4 iframe security posture is already correct — keep it

The current dictate iframe attributes are the recommended least-privilege
baseline and should not be relaxed:

- `sandbox="allow-scripts allow-same-origin allow-forms"` — start from nothing,
  add permissions one-at-a-time (the sources' stated philosophy). Do **not**
  add `allow-popups`, `allow-top-navigation`, or unrestricted flags.
- `referrerPolicy="no-referrer"` — prevents leaking the app origin to the frame.
- CSP `frame-src https:` (already in `tauri.conf.json`) — HTTPS-any because the
  host is user-configurable at bundle time; a single-pinned host needs a dynamic
  CSP/navigation-handler change (documented in the dictate skill).
- Cross-origin communication, if ever needed, must be `postMessage` with origin
  checks — never direct DOM access.

---

## 3. Proposed design

Introduce a distinction between the **shell** (the hosted Pro SPA) and the
**surfaces** that live inside it, and split delivery into two tracks.

```
                        ┌─────────────────────────────────────────────┐
                        │  nvoos-dictate webview (Tauri, thin)         │
                        │  App.tsx → AssistantView (single sandboxed   │
                        │  iframe)                                     │
                        └───────────────────┬─────────────────────────┘
                                            │ {hosted Pro SPA base}#/...
                                            │ (one origin, hash-only nav)
                                            ▼
                        ┌─────────────────────────────────────────────┐
                        │  Hosted Pro SPA (spa-v2) — the SINGLE shell  │
                        │  HashRouter + ChatSidebar/StatusBar chrome   │
                        │                                              │
                        │  #/chat           ← Home (assistant/chat)    │
                        │  #/knowledge-graph ← content-graph           │
                        │  #/media-studio    ← ported (Track B, M2)    │
                        │  #/document-editor ← ported (Track B, M2)    │
                        │  #/toolkit         ← ported (Track B, M3)    │
                        │  #/docs            ← ported (Track B, M3)    │
                        │  …               etc.                        │
                        └─────────────────────────────────────────────┘
```

For the **transient** period where a standalone SPA has not yet been ported
into the shell (Track B incomplete), Track A guarantees it still renders
without a reload by mounting one iframe per surface and toggling visibility.

### 3.1 Track A — reload-free hub (low risk, ship first)

Scope: `AssistantView` (dictate app) + small Rust/surface metadata change.
No change to `spa-v2`.

1. **Mount one iframe per surface, toggle visibility.** Replace the single
   mutable-`src` iframe with a list where every surface has its own iframe;
   the active one is shown, the rest are `display:none` (or wrapped in a
   keep-alive container). This is the industry-standard iframe keep-alive
   pattern and removes the reload-on-return for *every* surface, including
   Home/Chat.

2. **Lazy-mount on first visit, keep after.** Mount an iframe only when its
   tab is first activated; once mounted, keep it permanently (state preserved).
   This bounds memory/RAM while still avoiding reloads for visited tabs.

3. **Pin Home to the hosted Pro SPA root hash.** Give the `assistant` surface
   an explicit `route: '#/chat'` (see §3.3) so Home is a stable hash route, not
   a bare root URL. With keep-alive, returning to Home no longer reloads.

4. **Empty-state + route resolution unchanged** for the legacy single-`spa.url`
   fallback.

### 3.2 Track B — port standalone SPAs into the single shell (higher scope)

Apply the content-graph "vendored-upstream" recipe to each addon, turning each
into a `spa-v2` lazy route so it renders inside the shell (no per-addon chrome,
no reload, shares the ChatSidebar only when appropriate).

Per-addon checklist (mirrors PR #6998 / `mcp-ai-wpoos-spa-ui`):

1. Vendor the addon's `dist` JS/CSS into
   `addons/pro/assets/spa-v2/src/features/<slug>/upstream/` (never a `vendor/`
   folder). Keep upstream **byte-identical**; apply only the documented wrapper
   delta (IIFE → exported factory, `destroy()` teardown, namespaced document
   bindings, `destroyed` latch).
2. Add a React page (`<Feature>Page.tsx`) as an **imperative island**: render
   the addon's exact markup, write config to `window.*` in `useEffect`, call
   the factory, call `destroy()` on unmount.
3. Add the route to `router.tsx` (`React.lazy`) + a command-palette entry in
   `useBootstrap.ts` (`nav-<slug>`).
4. Add heavy deps to both `spa-v2`'s and the standalone app's `package.json`,
   with tsconfig `paths` + `src/shims/` entries where needed (mirror #7000).
5. If the addon makes jQuery `$.ajax` calls, add an `ajaxPrefilter` mirroring
   the fetch wrapper (PR #7002) or refactor to the shared fetch client.
6. Add `route` to the surface descriptor in `detect_surfaces()` (§3.3).
7. Test: spa-v2 vitest page contract, standalone app build, mirror build.

Porting order (by independent complexity; first batch is the user's explicit
set):

- **M2:** `media-studio`, `document-editor`, `toolkit-shell`
- **M3:** `docs-hub`, `canvas-toolkit`, `cloudways-dashboard`, `comic-reader`
- **M4 (evaluate):** `librechat`, `page-agent`, `algorave` (some may remain
  intentionally iframe-only — LibreChat is a full third-party app, best kept
  as an isolated iframe surface rather than vendored).

**Decision gate before M2:** confirm each addon's license allows bundling into
spa-v2 (MIT/Apache/BSD/ISC per the SPA skill; update `THIRD_PARTY_NOTICES.md`
and root `CREDITS.md`), and confirm bundle-size impact (esbuild `splitting:
false` inlines lazy pages — the content-graph page added ~630 KB; call out
growth per addon).

#### 3.2.1 RECORDED DECISION — heavy React SPAs stay iframe surfaces (Option 2)

**Decision date:** 2026-10-14. **Decided:** Track B Option 2 for `media-studio`,
`document-editor`, and `toolkit-shell` — they remain standalone shortcode pages
surfaced as Track A keep-alive iframes, **not** spa-v2 hash routes.

These three are **not** content-graph-shaped ports. `content-graph` is a
self-contained jQuery/Cytoscape IIFE (a ~5-line factory wrap); the other three
are full Pattern-A React SPAs with their own non-shared dependency trees and
cookie-nonce auth. Verified findings (this session):

| | content-graph (the precedent) | toolkit-shell | document-editor | media-studio |
|---|---|---|---|---|
| Kind | jQuery/Cytoscape IIFE, no React | React **19.1.0** SPA | React **19.1.0** SPA | React **19.2.6** SPA |
| Entry | `initNvoosGraphExplorer($)` | `createRoot().render(<App/>)` → `.nvoos-toolkit-shell-root[data-config]` | `createRoot().render(<App/>)` → `.nvoos-document-editor-root[data-config]` | `createRoot().render(<App/>)` → `.nvoos-media-studio-root[data-config]` |
| Heavy deps (not in spa-v2) | jquery, cytoscape, fcose (already in spa-v2) | Radix (`dialog`/`select`/`dropdown-menu`/`tabs`/`checkbox`), `@dnd-kit/*`, `@tanstack/react-table`, `react-hook-form`, `zod`, `sonner`, CVA | `@tiptap/*` (+ `@tiptap/pm` ProseMirror), `grapesjs`, `@grapesjs/react` | `konva`, `react-konva@19.2.3`, `react-image-crop`, `react-player`, `wavesurfer.js` |
| React vs spa-v2 | n/a | **matches** (19.1.0) | **matches** (19.1.0) | **skew** (19.2.6 vs 19.1.0) |
| Auth | `window.nvoosContentGraphAdmin` + REST, bearer/guest/basic via fetch wrapper | `window.NVOOS_TOOLKIT_SHELL` cookie `X-WP-Nonce` + `credentials: same-origin` | `window.NVOOS_DOCUMENT_EDITOR` cookie `X-WP-Nonce` + `credentials: same-origin` | `window.NVOOS_MEDIA_STUDIO` cookie `X-WP-Nonce` against `/nvoos-media-studio/v1/ai/*` |

Shared blockers for a true source port (Option 1), regardless of React version:

1. **Heavy dependency additions** — Radix/dnd-kit/table/zod (toolkit-shell),
   Tiptap+ProseMirror+GrapesJS (document-editor), or Konva/react-konva/react-image-crop
   (media-studio) would each inflate `pro-spa.js` and drag their own peer/version
   graph into spa-v2 (esbuild `splitting:false` inlines lazy pages — content-graph
   already added ~630 KB).
2. **Cookie-nonce auth mismatch** — all three use `window.NVOOS_*` + cookie
   `X-WP-Nonce` + `credentials: same-origin`; spa-v2's fetch wrapper is
   bearer/guest/basic. A source port must reproduce the cookie path (or refactor
   all three to the shared client) — non-trivial and, for the dictate iframe,
   unnecessary since the iframe's same-site child does cookie auth itself.
3. **`createRoot` imperative entry** — not a callable factory; the port would
   re-home React source (App + components + hooks + styles), not vendor a JS file.

**Conclusion:** these three stay **standalone shortcode pages surfaced as Track A
keep-alive iframes**. Track A already makes them reload-free and state-preserving.
The same default applies to other Pattern-A React SPAs with non-shared dependency
stacks (`canvas-toolkit`, `cloudways-dashboard`, `comic-reader`, etc.). A true
source port (Option 1) is reserved per-addon for surfaces light enough that
dependency/version additions are justified **and** that genuinely need shared
shell chrome; re-open only with a concrete dependency/version plan attached.

Consequence for §3.3: `media-studio`, `document-editor`, and `toolkit-shell`
keep `shortcode`/`class` (no `route`); Track A's keep-alive iframe is the
delivery mechanism for them.

### 3.3 Surface descriptor contract changes

Extend the `detect_surfaces()` catalogue so surfaces declare their shell
membership explicitly rather than implicitly via `route`:

- `assistant` → add `'route' => '#/chat'` (make Home a stable hash route).
- Ported addons (Track B Option 1, *only* for surfaces actually vendored into
  spa-v2) → switch from `shortcode` resolution to `route` fragments (e.g.
  `content-graph` → `'route' => '#/knowledge-graph'`). `media-studio`,
  `document-editor`, and `toolkit-shell` stay on `shortcode`/`class` — see §3.2.1.
- Unported addons → keep `shortcode`/`class` as-is (Track A keep-alive still
  makes them reload-free).

Add a one-line "surface kind" field only if the client needs to distinguish
"shell route" vs "standalone page" for styling; otherwise the existing `route`
presence-check in `Assistant.tsx` already suffices.

---

## 4. Implementation plan

### Track A (ship first, low risk)

| Step | File(s) | Change |
|---|---|---|
| A1 | `examples/nvoos-dictate/src/views/Assistant.tsx` | Replace single iframe with per-surface iframe list + visibility toggle; lazy-mount on first visit; keep-alive after |
| A2 | `addons/pro/includes/rest/class-wp-mcp-ai-pro-spa-surfaces-controller.php` | Add `'route' => '#/chat'` to the `assistant` surface |
| A3 | `addons/pro/tests/test-pro-spa-surfaces-controller.php` | Assert `assistant` carries `route === '#/chat'` |
| A4 | `examples/nvoos-dictate/src/lib/ipc.ts` | (unchanged unless a `kind` field is added) |
| A5 | Rust `nvoos/client.rs` | No-op confirmation: `SpaSurface.route` already deserializes `#/chat` |

**Validation for Track A:**
- `npm run build` in `examples/nvoos-dictate` (tsc + vite).
- `cargo test --lib` for the `SpaSurface` deserialize tests (add one for
  `#/chat` if needed).
- Manual: switch between Chat ↔ content-graph ↔ any standalone SPA; confirm no
  network reload (Network panel) and preserved state (scroll/partial input).
- `vendor/bin/phpunit addons/pro/tests/test-pro-spa-surfaces-controller.php`.

### Track B (per-addon ports, incremental)

For each addon, a self-contained PR following the §3.2 checklist and the
content-graph precedent. Suggested sequencing:

1. **M2a** `media-studio` → `#/media-studio`
2. **M2b** `document-editor` → `#/document-editor`
3. **M2c** `toolkit-shell` → `#/toolkit`
4. **M3** `docs-hub`, `canvas-toolkit`, `cloudways-dashboard`, `comic-reader`
5. **M4 (gate)** `librechat`/`page-agent`/`algorave` — decide keep-as-iframe vs port

**Validation per port:**
- spa-v2 vitest page test (mock the factory; assert config hand-off + destroy).
- `npm run build` for spa-v2 and the standalone `nvoos-pro-spa-vite`.
- `phpunit` for the updated `detect_surfaces()` (surface carries `route`).

---

## 5. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Keep-alive iframes increase memory (each mounted SPA is a full app) | Lazy-mount on first visit; cap mounted surfaces; document a "close surface" affordance if RAM becomes an issue |
| Bundling each addon into spa-v2 grows `pro-spa.js` (esbuild inlines lazy pages) | Call out per-addon bundle growth in PR body (content-graph precedent ~630 KB); consider a separate entry point if total exceeds budget (~170 KB gzip is the Pattern-A guidance, but the graph page already exceeds it — a separate entry/chunk strategy may be warranted) |
| Dual-React hazard when porting Pattern-A (bundled React 19) addons into spa-v2 | spa-v2 is itself a React app — confirm the vendored upstream is a **factory/imperative island**, never a second React renderer; reuse spa-v2's React, don't bundle the addon's |
| License/size non-compliance for a given addon | M4-style decision gate before each port; update `THIRD_PARTY_NOTICES.md` + `CREDITS.md` |
| iframe security regression | Keep current sandbox/referrerPolicy/CSP; never add unrestricted sandbox flags; `postMessage` with origin checks if shell↔dictate communication is ever added |
| Shared-worktree foreign WIP | `git diff <path>` before staging (SPA skill's shared-checkout hygiene) |

---

## 6. Open questions

1. **Home label:** should the `assistant` surface be relabeled "Home" or "Chat"
   in `detect_surfaces()` for clarity, and should it be pinned first and
   visually distinct from the other sub-tabs?
2. **Bundle budget:** for Track B, do we accept per-addon `pro-spa.js` growth,
   or split shell routes into a dedicated entry/chunk (the standalone Vite app
   already gets real chunking; the plugin esbuild build does not)?
3. **Keep-as-iframe surface(s):** confirm which addons are *intentionally*
   iframe-only (LibreChat is the likely candidate) and should therefore remain
   page/isolated surfaces even after Track B.
4. **Scope of "both":** confirm the user wants Track A + the full Track B port
   set in one effort, or Track A now + Track B sequenced across follow-up PRs.

---

## 7. References

- `addons/pro/includes/rest/class-wp-mcp-ai-pro-spa-surfaces-controller.php`
- `addons/pro/assets/spa-v2/src/router.tsx`, `.../useBootstrap.ts`,
  `.../features/knowledge-graph/KnowledgeGraphPage.tsx`
- `examples/nvoos-dictate/src/views/Assistant.tsx`,
  `.../src-tauri/src/nvoos/client.rs`
- Skills: `mcp-ai-wpoos-content-graph-spa`, `mcp-ai-wpoos-spa-ui`,
  `mcp-ai-wpoos-dictation-app`
- Proposals 060, 065; PRs #6998, #7000, #7002, #7063
```
