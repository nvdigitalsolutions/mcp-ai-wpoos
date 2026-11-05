# Proposal 115 — Forge completeness: inventory, wireframes, responsive rebuild

> Owner goals 2026-11-06: the forge should rebuild the WordPress site **as
> is** — every page the source site exposes, including pages the REST
> collections do not serve; it should recognize **one-pagers vs. multi-page
> sites** and the additional pages linked from home; and it should
> **recognize responsive behavior** (mobile / tablet / desktop) and carry it
> into the ADS rebuild. Where mature projects exist, adopt them instead of
> building.

## 1. Research summary (2026-11-06)

### Inventory discovery
- The XML sitemap is the canonical starting point (Exa: "7 methods to find
  all pages"; WP Sticky: "the XML sitemap is the easiest starting point").
  WordPress serves `wp-sitemap.xml` — an **index** of sub-sitemaps (up to
  2,000 entries each), and turns it off entirely when "discourage search
  engines" is set — so sitemap-only discovery can silently under-report.
- The robust rule (crawler.sh): **generate the inventory from an actual
  crawl** — pages that exist, answer 200, and are reachable by following
  links. Migration playbooks (WP→headless guides) add: "inventory every
  URL, categorize every pattern, map every redirect one-to-one, validate
  with a crawl diff."
- Wayback CDX enumerates every archived path per domain — the only
  reliable source for URLs the live site has since deleted (SEO.Domains).

### Wireframe-first rebuild
- Outside-in wireframing (HTMHell): structure first, section by section —
  the forge's semantic model already matches this.
- Content-first wireframes (mgifford): replace filler with **semantic
  placeholders describing what content belongs** — the template for our
  placeholder pages (a missing page is not dropped; it ships as a
  wireframe placeholder that names the content it expects).
- One wireframe per key page, header→hero→sections→footer order
  (Veravix) — our per-page `sections[]` order.

### Responsive recognition
- Breakpoints live in the source CSS as media queries (BrowserStack);
  ExtractCSS captures them from a site's CSS automatically — the same
  harvest we do with `css-tree` (robust `@media` prelude parsing vs. regex).
- Breakpoint **tokens** (Agriculture Design System, Syracuse): sorted
  ascending values drive array-style responsive tokens and generated media
  queries — the model for `breakpoints: ['mobile','tablet','desktop']` +
  per-breakpoint column counts.
- The industry-standard grid collapse (Elementor defaults): desktop N
  columns → tablet 2 → mobile 1. Captured when the source declares
  explicit per-breakpoint columns (`elementor-col-*` / `-md-` / `-sm-`),
  inferred otherwise.

### Mature projects — build vs. buy
| Project | Verdict | Why |
|---|---|---|
| `sitemap` (sitemap.js, TS-native) | **Adopt** | Resolves sitemap indexes — `wp-sitemap.xml` is an index; regex would miss the real pages |
| `css-tree` | **Adopt** | Proper `@media` prelude parsing for breakpoint extraction |
| Wayback CDX API | **Adopt** (pattern, no dep) | Deleted-page discovery (`--wayback` inventory source) |
| WP2Static / Simply Static / Staatic | Reference only | The crawl→render→snapshot loop is right; their output "is not a maintainable site" (ShiftPress) — the forge's ADS rebuild exists to fix that |
| linkinator / Crawlee | Reference only | Crawl design confirms ours; Crawlee is a scraping framework — too heavy for a bounded 2-file discovery |
| @mozilla/readability / Trafilatura | Rejected (v1) | Best-in-class article extraction, but single-main-content output destroys the section structure the classifier rebuilds. Possible prose-heavy fallback later |
| wget / HTTrack | Rejected | Out-of-process CLI orchestration is fragile cross-platform from Node |
| Anima / Relume / commercial wireframers | Rejected | Closed source |

**Dependency discipline:** `sitemap` + `css-tree` are forge-package deps
only — they are never vendored into generated projects (the template's
`package.json` is untouched; `src/forge/` stays dependency-free beyond the
existing `sanitize-html`/`limax` contract).

## 2. Site-shape recognition (one-pager vs. multi-page)

`discoverInventory` classifies the homepage navigation:

- **`one-pager`** — the nav is (almost) all same-page anchors (`#about`,
  `/#about`): every anchored section must exist on the rebuilt home page.
- **`multi-page`** — the nav links to distinct pages (`/about/`).
- **`hybrid`** — both (jayainc: anchor nav + a few missing sub-pages).

Each nav anchor is reconciled against the homepage HTML (`id=` lookup);
unmatched targets are recorded as `danglingAnchors` — a completeness gap
the report surfaces (the rebuilt home must not link into the void).

## 3. Wireframes

For every path the source exposes but the REST collections do not cover:

1. **Fetch the rendered page** (bounded: ≤ 8 pages, 15 s timeout, polite
   sequential — jayainc throttles).
2. **200** → `sanitizeHtml` → `classifyHtml` → semantic sections +
   responsive enrichment (below). Status **`captured`**.
3. **404/timeout** → status **`placeholder`**: one CTA section with the
   page title + a note naming the content it expects (content-first
   placeholder). The page still ships — the site structure survives and the
   client sees exactly what to rebuild in WordPress.
4. Stored on the profile (`wireframes: Record<slug, ForgeWireframe>`) — the
   committed, reviewable artifact. Never source markup: escaped text +
   media refs only (114 contract).

`ForgeWireframe`: `{ title, slug, source, extractedAt, status,
danglingAnchors?, sections: ForgeWireframeSection[] }` where
`ForgeWireframeSection = { section: SemanticSection, responsive:
ForgeResponsive }` — composition over modification: `semantic.ts` is NOT
touched (no conflict with the open classifier PR).

## 4. Responsive recognition

- **Breakpoints** (`extractBreakpoints`, `css-tree`): harvest
  `max-width`/`min-width` px values from the source stylesheets, sort
  ascending, label `mobile` / `tablet` / `desktop` (Elementor's canonical
  pair is 767/1025; fall back to defaults when the source has no
  breakpoints).
- **Columns per section** (`inferColumns`): grid kinds (feature-grid,
  icon-list, gallery, logo-cloud) collapse desktop `min(n,4)` → tablet 2 →
  mobile 1; explicit `elementor-col-*`/`-md-`/`-sm-` classes override
  (`columnSource: 'explicit' | 'inferred'`).
- **Rebuild:** the template's grid becomes breakpoint-driven via CSS custom
  properties (`--ds-cols-desktop/tablet/mobile` at the canonical
  breakpoints); DesignRenderer accepts pre-classified wireframe sections and
  passes the columns through. Breakpoint *values* are recorded on the
  wireframe for fidelity reporting; graduating to a per-site breakpoint
  token layer (the Agriculture/Syracuse pattern) is the documented
  follow-up.

## 5. Forge surface

- `forge analyze <url> --inventory [--wayback] [--write …]` — shape +
  inventory report; attaches `profile.inventory`.
- `forge analyze <url> --wireframes [--wayback] [--design] [--write …]` —
  implies inventory; extracts wireframes for the missing pages (design CSS
  feeds breakpoint extraction when `--design` ran).
- Scaffold: `profile.wireframes` → `src/lib/wireframes.generated.ts` +
  one static `src/pages/<slug>.astro` per wireframe (reserved slugs and
  the front page are skipped with a warning). Pages render via the existing
  `DesignRenderer` — no new template routing machinery.

## 6. Validation

- Unit: breakpoint extraction (css-tree), column inference + explicit
  overrides, sitemap-index resolution, one-pager/hybrid shape detection,
  dangling anchors, wireframe capture vs. placeholder, scaffold emission +
  reserved-slug guard.
- `tsc --noEmit`; the e2e exit gate (unchanged fixtures must stay green —
  wireframe emission is profile-gated).
- Live: `forge analyze https://jayainc.com --wireframes --wayback --write
  sites/jayainc.json` → placeholder wireframes for the deleted pages →
  scaffold → the forged site serves every URL the source ever exposed.
