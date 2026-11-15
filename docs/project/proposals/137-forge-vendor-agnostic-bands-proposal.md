# Proposal 137 — vendor-agnostic band extraction ("support any tech")

**Status:** core shipped 2026-11-09 · **Scope:** `examples/nvoos-astro-forge`
**Trigger:** building virtuallyjamaica.com (WPBakery + Brando) produced **zero**
design bands, so the profile carried no structure, `--geometry` had nothing to
measure, and the build silently degraded to content-driven.

## 1. Problem

`analyzeSections()` in `lib/design.ts` understands exactly one builder:

```ts
const SECTION_ID_RE = /elementor-element-([0-9a-f]{4,})/;
allSectionNodes(): only `.elementor-top-section`
```

Everything downstream assumes Elementor too — column widths from
`elementor-col-<n>`, copy/media widgets from `elementor-widget-heading|text-editor|aux_*`.
A site on **WPBakery, Divi, Bricks, Gutenberg, Beaver, Oxygen, Flatsome** or plain
HTML yields `sections: []`. The vocabulary pass had the same blind spot
(`collectWidgetNames` read only `elementor-widget-*`), so it reported
*"0 unknown widgets"* — indistinguishable from *"no widgets present"*, which
hides coverage gaps instead of surfacing them.

## 2. Research

| Source | What we took |
|---|---|
| **VIPS — VIsion-based Page Segmentation** (Cai, Yu, Wen, Ma; MSR-TR-2003-79) | The canonical vendor-agnostic segmentation: segment by **visual** presentation (background/borders/separators) rather than DOM class names, with a **Degree of Coherence** / content-density filter so a wrapper is not mistaken for a band. |
| **Wappalyzer** fingerprint model | The industry pattern for "support any tech" is a **declarative pattern registry** (`src/technologies/*.js` — regexes as data), not a chain of `if (builder === …)`. Adding a technology is data, never a code path. |
| Page-builder front-end markup (vendor docs) | `vc_row`/`wpb_column`/`vc_col-sm-N` (WPBakery), `et_pb_section`/`et_pb_row`/`et_pb_column_1_2` (Divi), `brxe-section`/`brxe-container` (Bricks), `wp-block-group`/`wp-block-column` (Gutenberg), `fl-row`/`fl-col` (Beaver), `flatsome`/`ux-builder`/`section`/`col large-N` (Flatsome UX Builder), `rev_slider`/`rs-module` (Slider Revolution). |
| Existing forge invariant (117 §2 / 124 / 136) | `design.sections` **drives rendering** and `verify`'s band-flow gate requires every entry to reach the page — so detected-but-unrenderable bands must not go there. |

## 3. Architecture

`lib/bands.ts` — three layers, tried in order, each a pure function of the DOM:

1. **Adapters (declarative).** One table row per builder: `detect` fingerprints,
   `band` containers, `column` containers, `width()` reader, `widget` flag.
2. **Semantic landmarks.** `<main>`'s element children when there are ≥ 2, else
   top-level `<section|header|footer|article>`.
3. **Visual (VIPS-lite).** The largest sibling set whose members each carry their
   own background (colour/image/gradient) and content — the VIPS cue, reduced to
   what a static HTML pass can see.

Two invariants make it hold up on real themes:

- **Column detection is orthogonal to band detection.** A theme can delete the
  builder's row wrapper while keeping its columns (see §5). When a band carries
  no column pattern of its own, every other adapter's column pattern is tried
  before falling back to an even split.
- **Breakpoint preference.** Builders emit several widths in one class list
  (`vc_col-sm-12 vc_col-lg-6`, `large-6 medium-6 small-12`). A first-match read
  takes the *mobile* value; `ofBreakpoint()` prefers `lg → md → sm → xs`
  (`large → medium → small`), because desktop is what the rebuild targets.

`design.outline` + `design.builder` are new profile fields and are explicitly
**non-rendering**: `sections` stays Elementor-only until a renderer model
exists. Putting skeleton bands in `sections` made `verify` fail the band-flow
gate — correctly.

## 4. Vocabulary (single source of truth)

**Band adapters** — `lib/bands.ts` → `BUILDER_ADAPTERS`:

| id | band | columns | width |
|---|---|---|---|
| `elementor` | `elementor-top-section` | `elementor-column` | `elementor-col-N` /12 |
| `wpbakery` | `vc_row`, `wpb_row` | `wpb_column`, `vc_column_container` | `vc_col-{lg,md,sm,xs}-N` /12 |
| `divi` | `et_pb_section` | `et_pb_column` | `et_pb_column_a_b` |
| `beaver` | `fl-row` | `fl-col` | `fl-col-*` /12, else even |
| `bricks` | `brxe-section`, `brxe-container` | `brxe-block`, `brxe-div` | even split |
| `gutenberg` | `wp-block-group`, `wp-block-cover`, `wp-block-media-text` | `wp-block-column` | even split |
| `oxygen` | `ct-section` | `ct-div-block`, `ct-new-columns` | even split |
| `flatsome` | `section` | `col` | `{large,medium,small}-N` /12 |
| `revolution-slider` | `rev_slider_wrapper`, `rev_slider`, `rs-module` | — | **widget**: one band, never descend |

Adapters whose `detect` never matches are inert, which is what makes generic
class names safe: `flatsome` keys on `ux-builder` / `section-content` /
`flatsome`, so a plain Bootstrap `.section` page is untouched (tested).

**Widget vocabulary** — `lib/vocabulary.ts` → `WIDGET_PATTERNS`: the same shape,
one row per builder, with a `structural` set (rows/columns/wrappers) and a
`NON_WIDGET` utility filter so the unknown bucket stays actionable.

### Adding a builder (two data rows, no logic)

1. `lib/bands.ts` → a `BUILDER_ADAPTERS` entry.
2. `lib/vocabulary.ts` → a `WIDGET_PATTERNS` entry.
3. A fixture in `tests/bands.test.ts` in the builder's real markup shape.

Nothing else branches on builder identity.

## 5. Findings from virtuallyjamaica.com

- **The theme can delete the row wrapper.** Brando emits **12 `wpb_column`s and
  zero `vc_row`s**, so a row-only fingerprint missed the builder entirely. The
  `detect` set now includes the column classes, and the bands come from the
  visual layer while `design.builder` still correctly reads `wpbakery`.
- **Breakpoint order matters.** `vc_col-sm-12 vc_col-lg-6` read first-match
  gives 100 %; preferring `lg` gives the real 50 %.
- **Vocabulary went from invisible to actionable**: "0 unknown widgets" became
  **2** (`button__link`, `file__button`) after the broader harvest, each with
  the `profile.vocabulary.widgetRoles` pin hint. The first pass over-harvested
  (8, including `library-inline-css` / `synced-color`), which is why the utility
  filter exists.
- **`sections` vs `outline` is a real distinction, not a naming choice.** Adding
  the detected bands to `sections` failed band-flow for all six; the outline
  field records the same structure with the gate still green.
- Six bands were recovered for the profile (`home`, `about`, `features`,
  `services`, `hardware` + one unanchored), with columns measured for half.

## 6. Phases

**Shipped (this proposal):** `lib/bands.ts` (9 adapters, 3 layers, 20 tests),
`design.outline` / `design.builder`, the `analyzeSections` hand-off, and the
generalised widget vocabulary (7 tests).

**Next, in order:**

1. **Render the outline.** Give `DesignRenderer` a skeleton-band mode
   (background + columns + flow), then promote `outline → sections` per builder
   and let the band-flow gate enforce it.
2. **`verify --geometry` against the outline.** `geometry.ts` keys on section
   ids; it needs to accept outline ids so band boxes can be diffed for
   non-Elementor sites.
3. **Title/divider facts for generic bands** (currently Elementor-only), so a
   rebuilt band keeps its heading treatment.
4. **Wayback/JS-rendered detail pages** — sliders and tab sets fetch their real
   markup at runtime, which is why `revolution-slider` is treated as one opaque
   widget.

## 7. Validation

| Gate | Result |
|---|---|
| `tests/bands.test.ts` | 20 passed (9 builders + fallbacks + determinism) |
| `tests/vocabulary.test.ts` | 7 passed |
| forge `npm test` | 700 passed (50 files) |
| virtuallyjamaica rebuild | 7 pages, build clean |
| `verify --seo --tracking` | language parity **91 %**, tokens, band flow, facts, tracking, SEO all pass |
| `bin/check-astro-app-parity.mjs` | clean (section-kind vocabularies still in sync) |
