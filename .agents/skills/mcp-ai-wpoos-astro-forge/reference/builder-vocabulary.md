# Builder vocabulary — vendor-agnostic band extraction (Proposal 137)

The design extractor was Elementor-only; any other builder produced **zero**
bands and the build silently degraded to content-driven. Evidence:
virtuallyjamaica.com (WPBakery + Brando) → `sections: []`, `--geometry` with
nothing to measure.

## Three layers (`lib/bands.ts`)

| Layer | What it does |
|---|---|
| **Adapters** (`BUILDER_ADAPTERS`) | Declarative rows: `elementor`, `wpbakery`, `divi`, `beaver`, `bricks`, `gutenberg`, `oxygen`, `flatsome`, `revolution-slider`. Each declares `detect` fingerprints, `band` / `column` containers, a `width()` reader, and `widget` (a slider is ONE opaque band, never descended). |
| **Semantic** | `<main>`'s element children when there are ≥ 2, else top-level `section` / `header` / `footer` / `article`. |
| **Visual (VIPS-lite)** | The largest sibling set where each member carries its own background (colour / image / gradient) and content — Cai et al., MSR-TR-2003-79, reduced to what a static HTML pass can see. |

Adapters that never match are inert, which is what makes generic class names
(`section`, `col`, `row`) safe: `flatsome` keys on `ux-builder` /
`section-content` / `flatsome`, so a plain Bootstrap `.section` page is
untouched (there is a test for exactly that).

## Adding a builder — two data rows, no logic

1. `lib/bands.ts` → a `BUILDER_ADAPTERS` entry.
2. `lib/vocabulary.ts` → a `WIDGET_PATTERNS` entry (`structural` set + the
   `NON_WIDGET` utility filter).
3. A fixture in `tests/bands.test.ts` using the builder's real markup shape.

Nothing branches on builder identity — the Wappalyzer model (regexes as data).

## Rules that cost real debugging

- **Column detection is orthogonal to band detection.** Brando emits **12
  `wpb_column`s and zero `vc_row`s**, so a row-only fingerprint misses the
  builder entirely. `detect` must include column classes, and bands found by the
  semantic/visual layers still get columns through the cross-adapter scan.
- **Prefer the largest breakpoint.** Markup carries `vc_col-sm-12 vc_col-lg-6`
  (or `large-6 medium-6 small-12`); first-match reads the MOBILE width.
  `ofBreakpoint()` reads `lg → md → sm → xs`.
- **`design.outline` is NOT `design.sections`.** `sections` drives rendering and
  the band-flow gate requires every entry to reach the page — placing skeleton
  bands there failed the gate for all six on virtuallyjamaica. Detected-but-
  unrenderable bands go to `outline`, with `design.builder` naming the
  fingerprinted tech (which can differ from `bands[].via`, the layer that found
  the boundaries).
- **Breakpoint / theme rewrites change the reported builder.** `builder` is the
  first adapter whose fingerprint matched, even when it yields no bands.

## The vocabulary pass is a coverage signal

`collectWidgetNames()` used to read only `elementor-widget-*`, so a WPBakery
site reported *"0 unknown widgets"* — indistinguishable from *"no widgets
present"*. It now harvests elementor / gutenberg / divi / bricks / wpbakery /
flatsome names with a `structural` set (rows, columns, wrappers) and a utility
filter, so the unknown bucket is actionable:

- over-harvesting first pass: 8 names incl. `library-inline-css`, `synced-color`;
- after the filter: **2** — `button__link`, `file__button`, each with the
  `profile.vocabulary.widgetRoles` pin hint (a profile-level fix, no release).

## Phases (see Proposal 137 §6)

1. Render the outline (skeleton-band mode in `DesignRenderer`), then promote
   `outline → sections` per builder and let band-flow enforce it.
2. `verify --geometry` against outline ids (geometry keys on section ids today).
3. Title/divider facts for generic bands (Elementor-only today).
4. JS-rendered detail pages: sliders/tabs fetch markup at runtime, which is why
   `revolution-slider` is one opaque band.
