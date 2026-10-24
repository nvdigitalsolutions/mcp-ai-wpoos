# Proposal 087 — Native Docs Hub Island in the Pro SPA (replace the iframe)

**Status:** Implemented (Phases 1–3 — see the implementation plan)
**Date:** 2026-10-24
**Related:** PRs #7078–#7080 (the iframe framing this replaces), PR #6998
(Content Graph explorer page — the pattern this proposal follows), the
`mcp-ai-wpoos-content-graph-spa` skill.

## Context

PRs #7078–#7080 gave the Pro SPA `#/docs`, `#/media` and `#/documents` pages.
Each framed a self-contained React app (Docs Hub, Media Studio, Document
Editor) behind a sandboxed `<iframe>`:

- `addons/pro/assets/spa-v2/src/features/docs-hub/DocsPage.tsx` framed the
  **hosted** Docs Hub front end (`https://console.nvoos.cloud/docs-hub/` by
  default, filter-overridable).
- Media Studio / Document Editor framed same-site shortcode pages and
  forwarded headless credentials into the frame via `postMessage`
  (`utils/auth-bridge.ts`).

The iframe was chosen to isolate each app's second React tree (React 19.1 vs
19.2, React Router v6 vs v7) from the Pro SPA. That isolation is real, but it
costs the standard iframe tax identified across the embedding industry:

- no shared theme/state with the shell, a competing history stack, broken or
  duplicated a11y context (focus traps, second document), doubled asset
  downloads, and a hard dependency on a hosted origin (the docs page died
  without console.nvoos.cloud even though the Complete bundle ships the Docs
  Hub plugin locally);
- headless credentials cannot cross the frame boundary as headers, forcing
  the `postMessage` auth bridge.

PR #6998 already solved this class of problem for the Content Graph: the
`#/knowledge-graph` page vendors the plugin's self-contained explorer bundle
**byte-identical** into the SPA tree and mounts it as an imperative island —
the React shell owns config + auth + page states; the vendored module owns the
graph. This proposal applies the same house pattern to the Docs Hub, and the
implementation plan extends it to Media Studio and Document Editor.

## Industry research summary (2026)

- **Native > iframe for first-party surfaces.** Iframes remain the right tool
  for untrusted third-party content, but first-party product surfaces should
  be headless/native: shared state, theming, routing, a11y and performance all
  degrade inside a frame (Embeddable's "8 reasons not to embed with iframes",
  Truto's headless-vs-iframe architecture guide, the single-spa micro-frontend
  corpus).
- **Docs UX standards are stable.** The GitBook/Mintlify/Docusaurus pattern —
  three-pane layout, search-as-you-type with keyboard navigation, anchor deep
  links, breadcrumbs, prev/next, light/dark theme — is exactly what the Docs
  Hub app already implements. The port must preserve the app, not rewrite it.
- **Island architecture.** Self-contained IIFE bundles mounted by a host
  shell (the repo's own knowledge-graph precedent) are the lowest-risk way to
  get native behavior without vendoring a second React dependency tree.

## Design

**Phase 1 (this PR) — Docs Hub island.** The `#/docs` page stops framing a
hosted URL and instead:

1. vendors the **built** Docs Hub bundle
   (`addons/docs-hub/assets/dist/docs-hub.{js,css}` — a self-contained IIFE
   bundling its own React 19.2/Router/markdown pipeline, so no dependency
   collision) into `features/docs-hub/upstream/`, byte-identical;
2. the Docs Hub entry point exposes an imperative island API —
   `window.NVoosDocsHub.mountDocsHub( container ) → { unmount() }` — and gains
   a **MemoryRouter mode** (`config.router: 'memory'`) so the island never
   touches `window.location.hash`, which the Pro SPA owns. Both are upstream
   docs-hub changes, so the vendored bundle stays byte-identical to the
   wp.org dist (zero wrapper delta);
3. `DocsPage.tsx` becomes the shell: injects `window.NVOOS_DOCS_HUB`
   (`apiUrl` → local `nvoos-docs/v1` REST base, nonce, island flags), mounts
   the island, tears it down on unmount, and keeps unavailable/error states
   mirroring the knowledge-graph page;
4. the `docsHub` endpoint in the runtime config switches from the hosted
   front-end URL to the **local REST base**, gated on
   `class_exists( 'NV_oOS_Docs_Hub_Plugin' )` exactly like `contentGraph`
   (plugin inactive → empty → unavailable state). The
   `wp_mcp_ai_pro_spa_docs_url` filter is retained as a REST-base override
   (behavior change documented: it no longer takes a front-end URL).

**Phase 2 — OKF knowledge source (implemented).** The Docs Hub island is now
a two-source browser: the existing `nvoos-docs/v1` manifest (default) plus an
OKF adapter that emits the same manifest shape from
`wp-content/uploads/mcp-ai-wpoos/knowledge/` bundles (markdown + YAML
frontmatter + cross-links — structurally identical to docs-hub content). The
adapter lives in the Pro addon (`mcp-ai-pro/v1/okf/docs`) because the OKF REST
surface and its auth already live there. `skill-knowledge` is read-only
auto-generated; `site-knowledge` is curated. One UI, two content backends, a
source toggle in the page header (auto-selects when only one source exists).
OKF cross-links are rewritten server-side to island hash routes using the
reader's own resolution convention (OKF v0.2 §6.1), extended with a
cross-bundle interpretation for paths escaping the bundle root.

**Phase 3 — Media Studio + Document Editor islands (implemented).** Same
vendored-island pattern with one refinement: these bundles are too large to
inline (2.7MB + 1.7MB — konva/wavesurfer and tiptap/grapesjs), so the pages
lazy-load them as `<script>`/`<link>` assets from URLs the runtime config
supplies (`*Asset` / `*Css` endpoints; the standalone app copies them into
its own build via Vite `?url` imports). The `postMessage` auth bridge is
gone: the pages inject the headless credential straight into the islands'
config globals (an `authorization` key the islands already honoured for
same-origin hosts), origin-checked against the API base. A capture-phase
guard keeps island skip links off the Pro SPA's hash.

## Why this shape

- **No React/Router collision** — the island bundles its own runtime, which is
  precisely why the knowledge-graph explorer works. The only new wrinkle is
  dual hash routers, solved upstream with the MemoryRouter mode.
- **No hosted dependency** — Complete-bundle sites serve docs from their own
  Docs Hub plugin; the console-hosted docs remain reachable only via the
  filter.
- **Sync discipline inherited from the knowledge-graph skill:** copy the
  built dist, keep it byte-identical, re-copy on upstream changes, re-run the
  spa-v2 suite (the page tests mock the island and assert the contract, not
  its internals).

## Costs / risks

- **Bundle size:** inlining the 660KB island grows `pro-spa.js` ~2.3MB
  (admin-only surface; the iframe previously downloaded the same app plus a
  full HTML document from another origin).
- **Filter semantics change:** `wp_mcp_ai_pro_spa_docs_url` now expects a
  `wp-json` REST base, not a front-end URL.
- **wp.org docs-hub surface unchanged:** hash-router auto-mount behaviour is
  the default; island mode is opt-in via injected config.
