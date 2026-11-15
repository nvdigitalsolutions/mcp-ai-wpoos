# Proposal 136 — Implementation plan (section vocabulary + agent surfaces)

Companion to `136-forge-section-vocabulary-and-agent-surfaces-proposal.md`.
Status: **not started** (2026-11-09). Phases P1–P6 below.

---

## 1. Scope

Six new section kinds (proposal §4.1), `data-section` emission + gate (§4.2),
`forge export-system` (§4.3), agent surfaces — `llms.txt` v2, `.md` page
variants, `MOTION.md`, `presets.json` (§4.4), and the structured-data rework
plus `--structured` verify gate (§4.5).

In: `examples/nvoos-astro-forge/**`, `templates/blog/**`, forge docs/skills.
Out: the bridge plugin, the deploy tiers (135), any runtime JS, any third-party
CSS.

### 1.1 Ordering (do not reorder)

```
P1 (data-section)  ──▶ P2 (kinds) ──▶ P3 (structured data + gate)
                          │
                          └────────▶ P4 (specimen + export-system)
P5 (agent surfaces) — independent, may run any time after P1
P6 (MCP + docs)     — after P2/P4
```

P2 is the long pole; P5 can ship while P2 is in review.

### 1.2 Invariants this plan must not break

1. `data-section-id` stays the **only** identity key (`builtBandsOf`,
   `sectionHtml`, `measureGeometry`, `scopedPaddingOf`). `data-section` is
   additive and advisory.
2. `classifyHtml` must stay **byte-identical** on form-less, kind-less input
   (114 P7's pin holds: the whole suite asserts it).
3. Generated output is deterministic: no timestamps in `llms.txt`, `.md`,
   `MOTION.md`, the specimen or the export README (the only allowed stamp is
   `nvoos.json`'s `generatedAt`, which already exists).
4. `forge.mjs` is plain JS — no TS syntax, no type specifiers, no parameter
   properties (096 gotcha).
5. Never emit a JSON-LD leaf that has no visible counterpart (§4.5).

---

## 2. Research summary (why each decision)

Condensed from the proposal §3.2; recorded here so the code comments can cite
one place.

| Decision | Authority | Consequence in code |
|---|---|---|
| Drop `FAQPage` | Google: deprecation notice 2026-05-08; docs removed 2026-06-15 | `seo.ts` stops emitting it; a dated comment says why |
| Do not add `HowTo` for `steps` | Google: HowTo docs removed 2023-09-14 | `Steps.astro` emits `<ol>` only |
| Every JSON-LD number must be visible text | Google *AI features and your website* (2025-12-10) | `verify --structured` gate |
| Prices need sale windows when on sale | Merchant listing guidance (2026-07-07): `validFrom`/`validThrough`/`priceValidUntil`, `priceType` | `Offer` builder refuses a `salePrice` without a window *and* without visible strikethrough |
| Product markup in initial HTML | Product markup guidance (2024-10-01) | Already true for a static forge build — assert it in the e2e |
| `llms.txt` v2 = H1 + blockquote + sections + `Optional`, links only | [llmstxt.org](https://llmstxt.org/) v2 (2026-08-10) | `lib/llms.ts` emits exactly that shape |
| `.md` page variants + `rel="alternate"` / `rel="describedby"` | llms.txt v2 | `*.md.ts` routes + `Base.astro` links |
| `llms.txt` has no SEO effect | Google changelog 2026-06-15 | Documented posture; never sold as ranking |
| Lighthouse audits llms.txt (agentic browsing) | llms.txt v2 text | Manual/Lighthouse check in the runbook |
| DTCG: rely only on the stable subset | DTCG 2025.10 is a preview draft ("do not implement this version") | No `$extends`, no `$root`, no `$ref` pointers; `$extensions` reverse-DNS for our metadata |
| Motion needs a pause control | WCAG 2.2 SC 2.2.2 (updated 2026-10-04) | `Marquee.astro` ships a persistent `<button aria-pressed>` |
| Scroll-triggered motion counts as auto-start | SC 2.2.2 note | 121's reveals stay neutral-at-load + reduced-motion-gated (already true) |
| `data-*` carries no a11y semantics | HTML standard | `data-section` rides beside `<section aria-labelledby>`, never replaces it |
| Specimen is the standard doc artifact | DTCG spec §3.5 (Storybook/Zeroheight/… category) | `export-system` ships `specimen.html` |
| Design-system bundle = tokens + scoped component CSS + specimen | reference repo `systems/<slug>/` shape | `<slug>.css` scoped under one root class |

---

## 3. File-by-file

### 3.1 `examples/nvoos-astro-forge/lib/types.ts`

Add:

```ts
export const SECTION_KINDS = [
  'hero', 'feature-grid', 'icon-list', 'testimonial', 'logo-cloud',
  'cta', 'gallery', 'text', 'form', 'catalog',
  'pricing', 'stats', 'steps', 'team', 'marquee', 'comparison',
  'content', // fallback
] as const;
export type ForgeSectionKind = ( typeof SECTION_KINDS )[ number ];
```

Slot types (all optional-tolerant; the classifier fills what the source has):

```ts
export interface PricingPlan {
  name: string; price: string; currency?: string; period?: string;
  features?: string[]; cta?: { label: string; href: string };
  featured?: boolean; salePrice?: string;
  priceValidUntil?: string; // only with a visible strikethrough
}
export interface StatItem { value: string; unit?: string; label: string; caption?: string }
export interface StepItem { title: string; body?: string; media?: string }
export interface TeamMember { name: string; role: string; photo?: string; bio?: string; sameAs?: string }
export interface MarqueeItem { label: string; icon?: string }
export interface ComparisonRow { label: string; values: string[] }
```

`ForgeSection` gains `pricing?`, `stats?`, `steps?`, `team?`, `marquee?`,
`comparison?` (parallel to the 117 `slots`); `SiteProfile` gains
`llms?: { title?: string; summary?: string; overrides?: string }` and
`exportSystem?: boolean`.

*Test:* `tests/types.test.ts` — enum completeness (every kind has a renderer
key map entry, asserted by importing the renderer's kind map from
`DesignRenderer.astro`'s sibling TS constant).

### 3.2 `examples/nvoos-astro-forge/lib/semantic.ts`

Add kind signals before the shape ladder, after structured-item extraction
(117). Required pattern per kind:

| Kind | Required evidence (all must hold) |
|---|---|
| `pricing` | ≥2 nodes carrying a price token (`[class*=price]`, `$`/`€`/`£` + digits, or a `<sup>` currency) **and** a plan label |
| `stats` | ≥2 value/label pairs where the value matches `/^[\d.,]+\s*[%+kKmM]?$/` |
| `steps` | an `<ol>` with ≥2 `<li>` **or** a numbered-index class pattern (`step-1`, `number-01`) |
| `team` | ≥2 `<figure>`s with a heading + an image, **or** `team-member` widget names |
| `marquee` | a track node with >8 repeated siblings **or** `marquee|ticker|logo-carousel` names **and** ≥4 items |
| `comparison` | a real `<table>` with ≥2 columns and ≥2 rows |

Extend `SEEDED_WIDGET_ROLES` / `SEEDED_BLOCK_ROLES`:

```
price-table|pricing-table|price-list → pricing
counter|odometer|stat-counter        → stats
process|steps|timeline               → steps
team-member|team-grid|our-team       → team
logo-marquee|ticker|client-strip     → marquee
comparison-table|compare-table       → comparison
```

Guardrails (the 117 regression class): a band that merely *mentions* a price
stays `feature-grid`; `pricing` requires ≥2 plans; `stats` requires the value
shape; never promote on a single matching class token.

*Tests* (`tests/semantic.test.ts`, +12):
`classifies a price-table widget as pricing`, `rejects a feature-grid that
mentions prices once`, `classifies an ordered process list as steps`,
`does not classify an unordered feature list as steps`, `classifies a
two-column table as comparison`, `classifies a logo ticker as marquee`,
`falls back to content for an unknown widget` (116 pin), `classifyHtml is
byte-identical with the new seeds disabled`.

### 3.3 `examples/nvoos-astro-forge/lib/design.ts`

`analyzeSections` adds per-kind extraction (facts only, never markup):

- `pricing` → `plans[]` from repeated plan cards; price string kept **verbatim**
  (including `$` and `/mo`) so §4.5 can string-match it.
- `stats` → `items[]` (value, unit split only when the source splits them).
- `steps` → `items[]` in document order.
- `team` → `people[]` with the figure image as `photo`.
- `marquee` → `items[]` labels; `speed` only if a CSS `animation-duration` fact
  resolves.
- `comparison` → `columns[]` + `rows[]` from `<th scope>`.

Add `analyzeBandKinds( html )` returning `{ id, kind }[]` for the `verify`
side (the built-HTML reader is `builtBandsOf`, extended to also read
`data-section`).

*Test:* `tests/design.test.ts` — one fixture band per kind, asserting the slot
shape (not just the kind), plus `keeps the price string verbatim`.

### 3.4 `templates/blog/src/components/design/` (six new files)

Each is a pure Astro component (no island), complete at rest, driven by CSS
vars from the band theme, with the a11y contract from §4.1 of the proposal.

- `Pricing.astro` — plan grid; optional monthly/annual `<fieldset>` of radios
  that is **presentational only** when both prices are present; feature lists
  as `<ul>`; featured plan via `--ds-plan-featured`. If the band has ≥2 plans
  × ≥2 shared features, render a `<table>` instead of cards.
- `Stats.astro` — `<dl>`; each `<dt>` holds value+unit as one text node and
  `<dd>` the label (so a screen reader does not split "98" / "%").
- `Steps.astro` — `<ol>`; step number announced via `<li value>` or an
  `aria-label`, not by a decorative `::before` alone (both: the visual counter
  plus a visually-hidden ordinal).
- `Team.astro` — `<figure>` + `<figcaption>` per person; `alt` = name.
- `Marquee.astro` — two tracks: the real one, and an `aria-hidden="true"`
  duplicate for the loop. `<button aria-pressed="false">Pause</button>` is
  always rendered (unless reduced motion is active — see below). The button
  toggles a `[data-paused]` attribute on the track; CSS reads it. Under
  `@media (prefers-reduced-motion: reduce)` the track is `animation: none` and
  the button is **not rendered** (nothing is moving, so no control is needed).
- `Comparison.astro` — `<table><caption>` + `<th scope="col|row">`; the
  responsive treatment is an overflow container (`overflow-x: auto`,
  `tabindex="0"`, `role="region"` + `aria-label`) — semantics never change.

`IconMarker.astro` is reused where a kind carries icons.

*Tests:* `tests/fixtures/kind-<name>.astro` rendered through the e2e build; each
asserted in `tests/forge-e2e.test.ts` (see §3.9).

### 3.5 `templates/blog/src/components/design/DesignRenderer.astro`

```astro
<div
  data-section={ SECTION_KINDS.includes( section.kind ) ? section.kind : 'content' }
  data-section-id={ section.id ?? undefined }
  id={ theme?.anchor }
  class:list={ [ 'ds-section-wrap', theme && `ds-section-wrap--${ theme.theme }`, ...behaviorClasses( behavior ) ] }
  style={ ... }>
```

Add the six branches to the existing kind switch, passing `theme` and the
110/117 section-theme facts unchanged. Every branch must consume the band facts
`verify` expects (title style, divider, alignment, padding) — the 114
fact-consumption gate will fail otherwise, which is the point.

*Gotcha to pre-empt:* the existing map uses `const theme = themeFor(...)`
(121 fixed the implicit-global bug) — reuse that exact form; a bare assignment
reintroduces `ReferenceError: theme is not defined` at prerender.

### 3.6 `examples/nvoos-astro-forge/lib/fact-audit.ts`

New marker:

```ts
{
  key: 'sectionKind',
  label: 'section kind',
  marker: 'data-section=',
  // The attribute sits on the band element itself, so the default
  // html.includes() over the band's own slice is sufficient.
}
```

Plus an enum assertion in `auditFacts`: the matched value must be in
`SECTION_KINDS` and must equal `section.kind` from the profile. A band whose
declared kind is `pricing` but renders `data-section="feature-grid"` is a
mismatch (this is the gate that makes P1 worth doing).

*Tests* (`tests/fact-audit.test.ts`, +4): `accepts a matching kind`,
`flags a kind mismatch`, `flags an out-of-enum kind`, `exists even when the band
declares no other facts`.

### 3.7 `templates/blog/src/lib/seo.ts` — structured data

1. **Remove `FAQPage`.** Delete the builder and the branch; leave the band and
   its `<details>` markup untouched. A dated comment:

   ```
   // FAQ rich results were removed by Google on 2026-05-07 (docs deleted
   // 2026-06-15). FAQPage markup no longer produces a search feature, so we
   // do not emit it. The visible <details> accordion is unaffected.
   ```

2. **Band nodes.** `buildPageGraph( page, sections )` gains:

   ```ts
   if ( kind === 'pricing' && plans.length ) graph.push( offerCatalogNode( bandId, plans ) );
   if ( kind === 'team'    && people.length ) graph.push( ...people.map( personNode ) );
   ```

   `offerCatalogNode` emits `OfferCatalog` → `Offer[]` with `name`, `price`,
   `priceCurrency`, and — only when a sale is both visible **and** dated —
   `priceValidUntil`. It returns `null` (and the caller skips) when any plan
   lacks a visible price string.

3. **Stable ids.** `@id` fragments are `#/pricing`, `#/person-<slug>` so the
   graph is diffable across builds.

*Tests* (`tests/seo.test.ts`, +6): `does not emit FAQPage`,
`emits OfferCatalog for a priced band`, `omits OfferCatalog when a price is
missing`, `emits Person nodes that reference the Organization`,
`refuses priceValidUntil without a visible strikethrough`, `@id fragments are
stable across two builds`.

### 3.8 `examples/nvoos-astro-forge/lib/seo-audit.ts` — the `--structured` gate

New pass, off by default, enabled by `verify --seo --structured`:

1. Parse the built page's JSON-LD `<script type="application/ld+json">`.
2. Collect leaf values of type number, or strings matching a price/date
   pattern (`/^\s*[^\d]{0,3}\d[\d.,]*/`, ISO dates).
3. For each, normalize (strip currency symbols, thousands separators, `$/mo`
   suffixes; lowercase) and require the normalized form to appear in the
   **text content of the band that contributed it** (band lookup by the
   `@id` fragment → `data-section-id`).
4. Report `{ missing: [{ band, path, value }] }`; `--strict-structured` fails.

*Tests* (`tests/seo-audit.test.ts`, +5): `accepts a price rendered as $98/mo`,
`flags a price only present in JSON-LD`, `flags a plan hidden at mobile width`
(markup present, text empty), `ignores non-fact strings`, `is skipped without
the flag` (back-compat pin).

### 3.9 `templates/blog/src/pages/_system/specimen.astro` (new, inert)

One page, every kind, neutral fixture data (`Acme`, `Northwind`, … never a real
client). Each kind is a `<section data-component="<kind>">` — the reference
repo's lift idiom, scoped to our own component set.

Scaffold renames `_system/` → `system/` and the page to
`system/specimen/` only when `exportSystem` is on (the `_ssr`/`_og` precedent;
keep the rename **source** a flat sibling: `_system/specimen.astro`, never
`_system/specimen/index.astro`, or Windows nests it — 096 gotcha).

The page links only `/<slug>.css` when built standalone and the normal Astro
bundle when built in-project; the export step rewrites the link.

*Test (e2e):* build with `--system-export`, assert `dist/system/specimen/`
exists, contains all 16 `data-component` sections, and has 0 serious/critical
axe violations (same Playwright harness as 126).

### 3.10 `examples/nvoos-astro-forge/lib/system-export.ts` (new)

```ts
export interface SystemExportOptions { target: string; slug: string; name: string }
export interface SystemExportResult {
  files: string[];
  warnings: string[];
  cssRuleCount: number;
}
export async function exportSystem( projectDir, options ): Promise< SystemExportResult >
```

Steps:

1. Require `dist/system/specimen/index.html` (fails closed with a clear error:
   "run `forge scaffold --system-export` first").
2. Read the built CSS bundle(s) referenced by the specimen.
3. `scopeCss(css, slug)`:
   - strip `[data-astro-cid-*]` attribute selectors;
   - strip `:where(...)` Astro wrappers by unwrapping;
   - prefix every top-level selector with `.<slug>` (comma-list aware);
   - leave `@media`/`@supports`/`@container` preludes untouched and recurse;
   - leave `@keyframes` names untouched (they are referenced by value).
4. Write `tokens.css` (flat `--forge-*` + `--nvs-*` aliases), copy
   `tokens.dtcg.json` with `$extensions["pro.nvoos.forge"] = { source,
   generatedFrom, kinds }`.
5. Copy `specimen.html`, rewriting the stylesheet link to `<slug>.css` and
   injecting `<div class="<slug>">` around `<body>`'s content when the bundle
   did not already wrap it.
6. Compose `README.md` (`composeSystemReadme(profile)`): kinds present with
   their slots, tokens in use, the contrast fixes applied, the motion contract,
   per-kind a11y rules, and:

   ```md
   ## Extracted from
   - Source: <origin> (analyzed <date>)
   - This package contains design language only. No source markup, CSS or copy
     is reproduced. Reuse of the source's brand assets is not granted by this
     export.
   ```

*Gotchas to encode:*
- A CSS selector containing `:is(a, b)` must not be naively comma-split — split
  at depth 0 only.
- `@import` statements must be hoisted, not prefixed.
- Astro emits `@layer` blocks in some configs; a prefixed selector inside a
  layer is fine, but do not strip the layer (cascade order changes).
- `content: ""` rules with `:before` must keep their pseudo-element after
  prefixing (`.<slug> .ds-step::before`).

*Tests* (`tests/system-export.test.ts`, +9): `scopes every top-level selector`,
`does not split :is() argument lists`, `hoists @import`, `preserves
@keyframes names`, `preserves @media nesting`, `requires a built specimen`,
`writes tokens.css and tokens.dtcg.json`, `README records provenance`,
`is deterministic (two runs byte-identical)`.

### 3.11 `examples/nvoos-astro-forge/lib/llms.ts` (new)

```ts
export function composeLlmsTxt( model: SiteModel, overrides?: LlmsOverrides ): string
export function composeMarkdownPage( entry: RenderedEntry ): string
```

`composeLlmsTxt` emits exactly the v2 shape and nothing else:

```md
# <site name>

> <one-paragraph summary>

<optional free text: what the site sells, who it serves, contact>

## Pages
- [/about/](https://site/about.md): Company background and team

## Products
- [/products/widget/](https://site/products/widget.md): Widget — $98

## Optional
- [Sitemap](https://site/sitemap-index.xml)
```

Rules: absolute URLs; `.md` targets (the v2 convention); one line of note per
entry, drawn from the entry's description/excerpt and truncated at a word
boundary; no timestamps; entries sorted by their canonical path (stable).

`composeMarkdownPage` is a narrow projection of our **sanitized** HTML:
`h1`–`h3` → `#`–`###`; `p` → paragraph; `ul`/`ol` → list; `a` → `[text](href)`;
`img` → `![alt](src)`; `table` → GFM table; `blockquote` → `>`; `<details>` →
`**Q** … **A** …`. Anything else → its text content. No external converter.

*Tests* (`tests/llms.test.ts`, +8): `emits the required H1 and blockquote`,
`uses absolute URLs`, `targets .md variants`, `sorts deterministically`,
`contains no timestamps`, `markdown projection preserves links and tables`,
`drops scripts and styles`, `overrides merge`.

### 3.12 `templates/blog/src/pages/llms.txt.ts` and `*.md.ts` routes

- `llms.txt.ts` — `GET` route, `Content-Type: text/plain; charset=utf-8`,
  assembled from the collections at build time.
- `posts/[...parts].md.ts`, `pages/[slug].md.ts` (and product routes when the
  storefront preset is active) — emit `text/markdown`.
- `Base.astro` head:

  ```astro
  <link rel="describedby" href="/llms.txt" />
  { markdownHref && <link rel="alternate" type="text/markdown" href={ markdownHref } /> }
  ```

*Tests (e2e):* `dist/llms.txt` exists and matches the v2 shape (H1 first,
  blockquote second, at least one `##` section); each post has a `.md`
  sibling; `Base` carries both links on a post page.

### 3.13 `templates/blog/scripts/` — `MOTION.md` and the drift gate

- `scaffold()` writes `MOTION.md` from `profile.design.behavior`:

  ```md
  # Motion contract

  Every band is complete at rest. Motion is additive, CSS-only, and
  `prefers-reduced-motion` returns the page to its at-rest state.

  | Band | Behaviour | Range | Reduced motion |
  |---|---|---|---|
  | `5e78df1` (hero) | parallax | 0 → +48px | static |
  | `fd63522` | reveal (fade-up) | 0 → +24px | static |
  ```

- `nvoos.generated.json` gains `llms.txt`-adjacent entries, `MOTION.md`,
  `system/specimen/index.html` (when exported), and the `.md` route outputs are
  **not** hashed (build artifacts) — only generated **sources** are.
  `scripts/check-generated.mjs` re-hashes sources only, unchanged in behaviour.

*Tests:* `tests/scaffold.test.ts` — `writes MOTION.md with one row per
behaviour`, `MOTION.md is deterministic`, `generated manifest lists MOTION.md`.

### 3.14 `examples/nvoos-astro-forge/lib/presets.ts` + `presets.json`

`serializePresets()` → `presets.json` (checked in, regenerated by a script and
asserted equal by a test):

```json
{
  "version": 1,
  "presets": [
    {
      "id": "storefront",
      "bestFor": "WooCommerce catalogues and product marketing",
      "detect": ["wc/v3 advertised", "product CPT present"],
      "kinds": ["hero", "pricing", "feature-grid", "testimonial", "cta"],
      "surfaces": ["products", "posts", "pages", "search"]
    }
  ]
}
```

*Test:* `presets.json matches lib/presets.ts` (fails on drift).

### 3.15 `examples/nvoos-astro-forge/scripts/forge.mjs`

```
forge export-system <profile.json> --target <dir> [--slug <name>] [--name "Display"]
```

- commander subcommand; plain JS.
- Validates the target is a forge project (`nvoos.json`) and has a built
  `dist/system/specimen/index.html`; otherwise exits 2 with the remediation
  line.
- Prints the file list + `cssRuleCount`, relative paths via `path.relative`
  (the 096 `dir.length` slicing bug — do not reintroduce).

### 3.16 `examples/nvoos-astro-forge/mcp/`

- `system_export` (write-gated, open-world policy like `design_apply`) →
  wraps `exportSystem`.
- `sections_list` (read) → returns the profile's bands with `{ id, kind,
  anchor }` so an agent can lift without reading HTML.
- Register in `mcp/registry.ts`; the router mode picks them up automatically.

### 3.17 Fixtures

`scripts/wp-fixture-server.mjs` gains a `kinds-page` with one band per new kind
(hex section ids — `a1b2c3d`, `b2c3d4e`, … — the 125 gotcha; a human-readable
id silently loses the facts chain).

The e2e (`tests/forge-e2e.test.ts`) adds:
`renders all six kinds`, `every band carries data-section`,
`specimen has 16 data-components and 0 serious axe violations`,
`llms.txt matches the v2 shape`, `post .md siblings exist`,
`no FAQPage in any built page`, `OfferCatalog appears only where a price is
visible`, `--structured passes on the fixture and fails on a doctored page`.

---

## 4. The rules to remember

1. **`data-section-id` = identity, `data-section` = kind.** Never swap them;
   never make layout or verifier *correctness* depend on the kind.
2. **Six kinds require structural evidence, not a class token.** `pricing`
   needs ≥2 plans; `steps` needs an ordered list; `comparison` needs a table.
3. **A band with no visible price emits no `Offer`.** The gate is the feature.
4. **`FAQPage` and `HowTo` are dead.** Dated comments explain why; do not
   re-add.
5. **Generated artifacts carry no timestamps** (except `nvoos.json`).
6. **The specimen is the component set** — never a second implementation.
7. **A marquee without a persistent pause button is a Level A failure**, not a
   style choice.
8. **The export package is design language only**, with a provenance block.

---

## 5. Validation (to run per phase)

```sh
cd examples/nvoos-astro-forge
npm test                 # unit (adds ~44 tests across the new suites)
npm run typecheck        # vitest does NOT typecheck — this is part of the gate
npm run test:e2e         # fixture → scaffold → install → astro check + build → asserts

# P4 only
node scripts/forge.mjs scaffold site.json --target ./out --system-export
cd out && npm install && npm run build && cd ..
node scripts/forge.mjs export-system site.json --target ./out --slug acme
ls out/system   # tokens.css tokens.dtcg.json acme.css specimen.html README.md

# P3 only
node scripts/forge.mjs verify site.json --target ./out --seo --structured

# P1/P2 regression watch — run against a known-good profile and diff
node scripts/forge.mjs analyze <url> --design --sections --write /tmp/after.json
```

Expected gate outcomes:

| Gate | Pass condition |
|---|---|
| `npm run typecheck` | clean (CI fails on type drift even when vitest is green) |
| `test:e2e` | all previous assertions unchanged + the eight new ones |
| `verify` (default) | no new fact failures on the jayainc-shaped fixture |
| `verify --seo` | unchanged |
| `verify --seo --structured` | 0 missing on the fixture |
| Specimen axe | 0 serious/critical |
| Standalone specimen | serves with only `<slug>.css`, 0 console errors, sampled computed styles match |

---

## 6. Operator runbook

**Adding a new kind** (the checklist that keeps this from drifting):

1. Add to `SECTION_KINDS` in `lib/types.ts` + the slot interface.
2. Add classifier evidence in `semantic.ts` + the seed maps.
3. Add facts extraction in `design.ts#analyzeSections`.
4. Add the component in `components/design/` (a11y contract documented in its
   header comment).
5. Add the branch in `DesignRenderer.astro`.
6. Add it to `_system/specimen.astro` + fixture data.
7. Add it to the e2e kind list.
8. Add its JSON-LD posture to `seo.ts` (even if the answer is "none" — write
   the comment with the authority).
9. Update `presets.json` if the preset should include it.
10. Update the skill (`mcp-ai-wpoos-astro-forge`) §"Template contract".

**Exporting a design system for a client:**

```sh
forge scaffold sites/acme.json --target ./acme --system-export
cd acme && npm install && npm run build && cd ..
forge export-system sites/acme.json --target ./acme --slug acme
# review acme/system/README.md provenance block before delivery
```

**Verifying agent surfaces:**

```sh
curl -s http://localhost:4199/llms.txt | head -20        # H1 + blockquote
curl -s http://localhost:4199/posts/<slug>.md | head     # markdown variant
curl -sI http://localhost:4199/ | grep -i describedby    # or inspect the head
npx lighthouse http://localhost:4199/ --only-categories=  # agentic-browsing audit
```

---

## 7. Known limits

- **Scope-CSS fidelity is the one genuinely risky step.** Astro's component
  scoping is attribute-based; stripping it is a text transform, and a
  sufficiently exotic selector (nested `:is()` inside `@layer` inside
  `@container`) can defeat naive prefixing. The standalone-specimen gate is
  the mitigation; the fallback deliverable (built CSS bundle + tokens +
  specimen, no single scoped file) is still useful, and shipping it must be a
  documented degradation, not a silent one.
- **`llms.txt` is not a ranking play.** Google states no effect; the value is
  agent consumption + the Lighthouse check. If a client asks for it "for SEO",
  the answer is the cited changelog.
- **`.md` variants are lossy by design.** Complex builder HTML projects to
  their text structure; the HTML remains canonical and `rel=canonical` still
  points at it.
- **No `HowTo`/`FAQPage` revival.** If Google reintroduces either, that is a
  dated code comment + tests away — not a schema-shaped guess today.
- **`export-system` needs a built specimen.** It is a post-build step; it
  cannot run before `astro build` and it deliberately does not take
  screenshots.
- **DTCG stays on the stable subset** until the 2025.10 draft leaves preview;
  `$extends`/`$root`/property-level `$ref` are out.
- **Motion stays CSS-only.** No JS choreography, no libraries, no runtime.
