# Proposal 065 — NV oOS Content Graph as NPM Packages

**Status:** Draft for discussion
**Date:** 2026-10-11
**Related:** PR #6998 (Content Graph explorer page in the Pro SPA)

## Context

PR #6998 ported the NV oOS Content Graph explorer into the Pro SPA
(`#/knowledge-graph`) by vendoring the plugin's JS verbatim
(`plugins/nvoos-content-graph/assets/js/{content-graph-admin,content-graph-theme,content-graph-icons}.js`).
That work surfaced a structural question: should parts of the Content Graph
front-end stack become publishable NPM packages like the 17 existing
`packages/nvoos-*` modules?

This proposal evaluates three extraction options against the repo's own
package rubric (from `packages/README.md` + `FINAL_SUMMARY.md`):

- **Zero WordPress globals** — no `window.wp*` dependencies
- **Zero runtime deps** (or optional peers) — each package independently usable
- **ES modules + generated `.d.ts`** via the `adapt-for-npm.cjs` pattern
- **MIT license**, injectable configuration, dist/ tracked in git
- **Precedent:** jQuery-dependent candidates were rated **Low** portability
  ("needs full rewrite") and rejected (`ajax-error-service.js`,
  `accessibility-enhancements.js`)

## Option A — `nvoos-graph-theme` (theme engine + icon registry)

**Scope:** `content-graph-theme.js` (635 LOC) + `content-graph-icons.js`
(122 LOC).

**What it is:** deterministic WCAG 2.2 contrast math
(`ensure_contrast`, `luminance`, `contrastRatio`), Okabe-Ito colorblind-safe
fallback palettes, per-type color resolution, community/degree color ramps,
the Cytoscape stylesheet builder, layout presets, chrome CSS-variable tokens,
and the 24-glyph SVG icon catalogue.

**Fit against the rubric:**

| Criterion | Verdict |
|---|---|
| WP globals | None — `window.matchMedia` and `document.body.className` only, both injectable |
| Runtime deps | Zero (pure functions; the only output consumer-adjacent piece is `buildStylesheet`, which just returns JSON-ish style arrays) |
| ES module conversion | Mechanical — it is already a clean IIFE attaching one global; the `adapt-for-npm.cjs` pattern (IIFE → exports + config injection) applies directly |
| Precedent | Strongest match in the whole repo — closer to the Tier 1 packages than several that shipped |

**Why it matters beyond packaging:** the same file now exists in **three
places** — plugin admin, plugin front-end embed, and the spa-v2 `upstream/`
copy added by PR #6998. A published package consumed by all three eliminates
the copy-drift and gives the plugin a single upstream for the visual system.

**Rough effort:** 0.5–1 day. Port, `adapt-for-npm.cjs`, `.d.ts`, README,
credits, one test file mirroring the plugin's Visual test suite.

## Option B — `nvoos-content-graph-client` (typed REST client)

**Scope:** a zero-dependency promise client for the `nvoos-content-graph/v1`
REST namespace — `/graph`, `/nodes`, `/nodes/{id}`, `/edges`, `/search`,
`/graph/visual-config`, `/export` — with injectable `endpoints`, `headers`,
`fetch`, and `credentials` (the exact `nvoos-chat-memory` /
`nvoos-cron-status` shape).

**Fit against the rubric:** perfect — it is the same SDK-tier pattern as the
Tier 5/6 packages. Zero deps, no DOM, no framework.

**Consumers:** the spa-v2 `KnowledgeGraphPage` (which currently hand-rolls
its visual-config fetch), future non-WP dashboards, the AI addon's front-end.

**Rough effort:** 1–1.5 days including types and tests.

## Option C — Full explorer widget package

**Scope:** `content-graph-admin.js` (1,438 LOC) as a self-contained widget:
jQuery + Cytoscape + fcose + the REST surface.

**Fit against the rubric:** **fails it.**

- It is a **jQuery IIFE by design** — PR #6998 deliberately kept the body
  byte-identical and bundled jQuery rather than rewriting it. Packaging it
  "properly" means exactly the de-jQuery rewrite the SPA port avoided.
- Its contract is WordPress-shaped: it reads `window.nvoosContentGraphAdmin`
  / `window.nvoosContentGraphTheme` / `window.cytoscape` globals and expects
  a WP REST backend. Cleaning that into an injectable-config ES module is a
  rewrite, not an extraction.
- **No clear consumer.** The out-of-WordPress use case (embed the graph
  anywhere) is already served better by the pairing A + B: theme engine for
  rendering decisions, REST client for data. Whoever wants a widget can
  assemble it from those two with any renderer (Cytoscape, D3, sigma, React
  Flow) — the repo's own philosophy is "independently usable modules," not
  "port the WordPress widget."
- jQuery-carrying candidates were explicitly rated **Low** in
  `packages/FINAL_SUMMARY.md` — this is that profile, at 3× the size.

**Verdict:** do not extract as a package. The SPA sharing mechanism (single
source tree via Vite alias + documented vendored delta) is the right
long-term home for the explorer code.

## Recommendation

1. **Ship Option A first** (`nvoos-graph-theme`) — cheapest, highest rubric
   fit, and it immediately fixes the three-way copy-drift introduced by the
   SPA port. The plugin admin/front-end/spa-v2 all consume the package at
   build time.
2. **Ship Option B second** (`nvoos-content-graph-client`) — SDK-tier
   consistency; the spa-v2 page consumes it, removing its hand-rolled fetch.
3. **Reject Option C** — record this decision so the explorer stays
   plugin/SPA-owned.

## Open questions

1. **Licensing intent** — packages are MIT while the plugin is GPL. The theme
   engine and REST client are NV-owned code (precedent: the 17 existing
   packages), but the `adapt-for-npm.cjs` step should record the
   GPL→MIT intent explicitly, as some existing packages do.
2. **Publication** — follow `docs/npm-alpha-publishing.md`; do A and B go out
   as alpha (`@nvdigitalsolutions/nvoos-graph-theme@next`-style) or wait for
   a consumer to exist?
3. **Version drift window** — between extracting A and refactoring the plugin
   to consume it, the plugin keeps its bundled copy (the package becomes the
   upstream on the next plugin release). Confirm that sequencing.

## Out of scope

- De-jQuerrying the admin explorer (a rewrite for its own proposal, if ever)
- Extracting any PHP (visual tokens config stays server-side)
- Changing PR #6998's vendoring approach as a precondition for either package
