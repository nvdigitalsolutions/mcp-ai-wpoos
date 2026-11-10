# Render-side bands — making a vendor-agnostic source actually render

2026-11-10. Proposal 137 (the analyzer half) taught the extractor to *detect*
bands on any builder. This is the RENDER half: what still broke when a WPBakery
+ Brando source was rebuilt, and the rules that fixed it. Every item below cost
a debugging cycle on virtuallyjamaica.com; the symptoms are distinctive, so
recognise the symptom and jump to the rule.

## 1. The classifier only split Elementor/Gutenberg → one giant band

`isSectionBoundary()` matched `elementor-section`, `wp-block-group` and
`wp-block-cover` only. Brando emits `<section class="brando_row_…">` with
`wpb_column` children and **no** `vc_row`, so:

- `hasBuilderMarkers()` found nothing (`VC_WRAPPER` was `vc_[a-z_]+`, which does
  not match `wpb_`), and
- the whole page classified as ONE `text` section.

Symptom: **every band surface collapses into band 0**. Positional theming then
paints the entire page with the first band's background (virtuallyjamaica's red
hero covered the whole document).

Rule: `isSectionBoundary()` treats a semantic `<section>` element as a band, plus
`vc_row`/`wpb_row` classes and a widened `(vc|wpb)_[a-z_]+` marker. When the
region has no Elementor widgets and no structured blocks, the classifier splits
it on those boundaries and emits one raw-text section per band (the band's own
sanitized markup — nothing is invented). A single-boundary document keeps the
old post-body path.

## 2. `sliceHtml` — htmlparser2 `endIndex` is INCLUSIVE

```ts
// WRONG — truncates the final `>` of every closing tag
html.slice( node.startIndex, node.endIndex )     // → "</section"
// RIGHT
html.slice( node.startIndex, node.endIndex + 1 )
```

Symptom: the built page has N `<section` but ONE `</section>`; the browser nests
every following band inside the previous one. In the DOM that reads as
`nestedWraps: 6` inside band 0, band heights of 8 000px, and the same text
repeated down the page. `grep -c '</section>'` on the built HTML is the fastest
diagnosis — opens and closes must match.

## 3. `bandSource` must come from the profile, not from `sections.length`

`analyze` PROMOTES the detected outline into `design.sections` *and* records
`design.bandSource: 'outline'`. The scaffold re-derived the flag from
`sections.length > 0`, so promoted outlines switched to Elementor-id matching —
but there are no Elementor ids in the rebuilt DOM, so no band themed. The
profile's own flag is authoritative:

```ts
const bandSource = profile.design?.bandSource
  ?? ( profile.design?.sections?.length ? 'sections' : 'outline' );
```

`tests/design-bands.test.ts` pins the promoted-outline case.

## 4. Sanitizer v3 — the presentational subset (and what it deliberately drops)

An outline-only source is rebuilt from its OWN markup, so a dropped `style`
attribute erases the only record of a panel colour or a photo column. Dropping
*everything* flattened the red "how it works" panel and both photo columns into
the page surface.

`SANITIZE_VERSION = 3` keeps exactly:

| Kept | Why |
|---|---|
| `background-color` (flat hex/rgb/named) | panel + band surfaces |
| `background-image: url(...)` (http(s) / root-relative) | photo panels; the `background:` shorthand is normalized to the longhand |
| a **derived** `color` | WCAG luminance of the kept background → `#ffffff` or `#1a1a1a`. NEVER the source's own `color` declaration |
| `class` on `<i>` | icon-font glyphs (`icon-flag icon-large`); without it every icon is an empty box |

Still dropped: layout/positioning, `!important`, `url()` in `background-color`,
non-http schemes, and every source `color`. A `<!-- style -->` strip test asserts
the hostile cases die.

Two consequences worth remembering:

- **Derived ink is not optional.** A kept `background-color` with the site ink
  painted `#626262` on `#dd3333` (2.6:1). The sanitizer appends the derived ink
  in the same declaration block.
- **Shortcode artifacts render as copy.** `[ultimate_spacer height="25"]` shipped
  as visible text; `textFilter` drops `[name …]` shapes (digit-leading citation
  brackets are untouched).

## 5. The WPBakery/Brando layout shim (template CSS)

The builder's stylesheet never ships, so `.row` stacked. The markup already
carries the structure — re-express it in flex rather than matching theme classes:

```css
/* rows: .row, .vc_row, inner rows, owl carousels */
.ds-section-wrap .post-body :is(.row, .vc_row, .brando-inner-row-class,
  [class*='brando-inner-row'], [class*='brando_inner_row'], .owl-carousel) {
  display: flex; flex-wrap: wrap; align-items: stretch; gap: clamp(1.25rem, 2.5vw, 2.5rem);
}
.ds-section-wrap .post-body :is(.wpb_column, .vc_column_container) { flex: 1 1 0; min-width: 0; }
.ds-section-wrap .post-body .owl-carousel > * { flex: 1 1 8rem; }   /* logo row */
/* a photo panel: the source sized it with an inline min-height we drop */
.ds-section-wrap .post-body :is(.wpb_column, .vc_column_container)[style*='background-image'] {
  min-height: clamp(18rem, 36vw, 40rem); background-size: cover; background-position: center;
}
```

Gotchas inside the shim:

- **`:has()` cannot be nested.** `:has(> div:has(h1))` is invalid CSS and the
  ENTIRE rule is dropped silently. Use flat relative selectors:
  `:has(> div > h1):has(> div > img)` selects a row whose children hold the copy
  and the illustration.
- Icon markers need explicit sizing: `i[class*='icon-'].icon-large` is a block
  glyph above its label; the small ones sit inline; `i[class*='fa-']:empty`
  is hidden (the rebuild does not load Font Awesome).
- The hero split rule keys on STRUCTURE (`has(> div > h1)`), not on the resolved
  theme — a red hero resolves to `dark`, not `hero`, so a `--hero`-scoped rule
  never matches.

## 6. The content-layer cache bites after a sanitizer change

`astro build` reuses `.astro/` content-layer entries. After changing
`lib/sanitize.ts` the DOM kept the OLD sanitization while other changes landed
(icons appeared, styles did not — or vice versa). `rm -rf .astro` (or a clean
re-scaffold) before concluding a sanitizer change "did nothing".

Also: a dev/preview server serves cached HTML. When a curl of the built file
shows the new markup and the browser does not, the browser is the stale one —
re-navigate with a cache-busting query.

## 7. Chrome facts the analyzer does not harvest (pin them)

Transparent overlay headers (`nav.navbar.transparent-header`) and widget-area
footers escape `analyzeChrome`. Measure them and pin `design.chrome` in the
profile:

- `headerBg` / `headerInk` — the source header sits ON the hero; a white strip
  was the loudest mismatch. `#dd3333` + `#ffffff` fixed it.
- `nav` (labels + hrefs, in SOURCE order — `navAnchors` is alphabetical and
  labels are derived from the anchor), `navStyle` (12px / 600 / uppercase /
  0.26px measured), `logo` (the SVG wordmark, not the text fallback).
- `footerLogo`, `footerBg`, `footerBarBg`, `footerInk`, `copyright`, and
  `social` (new fact: `{ label, href, icon }`; the template maps the source's
  `fab fa-*` class to an inline glyph, so no Font Awesome is needed).
- Numeric tokens the scale harvest gets wrong go in the profile's top-level
  `tokens` (`scale-h1: 55px`, `scale-h2: 28px`). A pin under `tokens.*` writes to
  `design.tokens`, which nothing reads — that is a silent no-op, not an override.

## 8. Sanity table (virtuallyjamaica.com, 2026-11-10)

| band | source | built | structure restored |
|---|---|---|---|
| hero | 900 | 746 | copy/illustration split |
| about | 504 | 471 | 2 columns (image + copy) |
| wifi / how it works | 1120 | 686 | photo column + red panel, 2×2 items, 4 icons |
| features | 1339 | 1277 | photo left + accordion right, 8 icons |
| services | 871 | 616 | 3 columns: red panel + phone photo + navy panel |
| hardware | 647 | 297 | 7 logo marks in one owl row |
| contact | 900 | 675 | photo band + form |

Remaining deltas are source `min-height`/padding we do not harvest, and equal
column widths where the source authored 25/33/41 (the profile HAS the measured
widths; emitting per-column `flex-basis` is the next increment).
