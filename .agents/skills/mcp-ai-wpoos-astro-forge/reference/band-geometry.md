# Band geometry — matching the source's boxes, not just its colours

How `analyze --geometry` records a source band's box, and how the scaffold turns
that measurement into CSS on the rebuilt band. Written from the virtuallyjamaica
rebuild (2026-11-10), where the bands had the right content, colours and order but
the wrong *size* — hero 746px against the source's 900, contact 675 against 900,
services 616 against 872.

The governing rule: **measure, never scavenge**. Builder themes declare band and
column rhythm through classes and inline styles that the sanitizer strips, so the
markup the rebuild receives contains none of it. `getComputedStyle` in a real
browser is the only faithful source.

---

## 1. What the measurement records

`lib/geometry.ts` → `measureGeometry()` writes one box per band per viewport
(desktop 1440, tablet 834, mobile 390):

```ts
interface BandGeometry {
	height: number;          // border-box, getBoundingClientRect().height
	width: number;
	paddingTop: number;      // getComputedStyle — resolves the class-based padding
	paddingBottom: number;
	paddingLeft?: number;    // optional: baselines recorded earlier stay valid
	paddingRight?: number;
	marginTop: number;
	marginBottom: number;
}
```

Result shape: `Record<bandId, Record<'desktop'|'tablet'|'mobile', BandGeometry>>`,
stored on `profile.geometry`.

## 2. Source selector strategy (the Elementor assumption)

The original source selector was hardcoded:

```ts
kind === 'built' ? `[data-section-id="${id}"]` : `.elementor-element-${id}`
```

That silently measures **nothing** on an outline-band builder such as
WPBakery/Brando (virtuallyjamaica), Bricks or Oxygen — those sources have no
Elementor class at all, so the baseline came back empty and `verify --geometry`
then had no expectations and passed vacuously.

Resolution now:

1. `.elementor-element-<id>` — exact for Elementor sources, tried first;
2. `options.selectors[id]` — the caller supplies the band's own anchor (`#about`);
3. no `#<id>` fallback — a profile id is a hash (`45f5e88`) and `#45f5e88` is an
   **invalid selector that throws**;
4. every candidate is guarded by `try/catch` inside `page.evaluate` — one
   malformed selector must cost one band, never the whole baseline. (Before the
   guard, a single throw aborted all seven bands.)

`analyze --geometry` builds the selector map from the band anchors and reports
real coverage — `✓ Geometry baseline: 7/7 band(s)` — warning when a band measured
nothing instead of printing a success line over zero measurements.

### `--geometry` must not force `--design`

`analyze` builds a *draft* profile and merges it into `--write` **after** the
geometry step, so the draft has no design block. The step originally required
`--design`, which re-extracts the design block and overwrites hand-tuned
`design.chrome` / `design.tokens`. It now reads the bands from the profile being
written, exactly as `--text` already did.

## 3. Promote a column anchor to its band

A source anchor can sit on a **column**, not the band row — Brando puts
`id="wifi"` on a `wpb_column`. The rebuild draws the band, so measuring the
column compared a 720px half-width box against a 1440px band and reported a
phantom width failure on every run.

When the matched element carries `wpb_column` / `vc_column_container`, walk up to:

1. the nearest `<section>` — the builder's band wrapper, and it carries the
   band's own padding; **preferred**;
2. otherwise the nearest `.row` / `.vc_row` / `brando[-_]inner[-_]row`.

Do not promote to `.row` first: the row is an inner 12-column grid with none of
the band's padding, so width becomes right and padding is lost (observed:
`1ff4891` went 720→1440 with padding 58/58 → 0/0, and the band height then read
1076 instead of 946).

## 4. Emitting the measurement into `sections.generated.ts`

`lib/scaffold.ts` → `renderSectionsGenerated()` emits two new fields per band.

### `padding` — per device, four sides

```ts
function measuredBandPadding( section, geometry ): ResponsiveLength | undefined
```

- emits `'130px 0px 130px 0px'` when all three viewports agree;
- emits `{ desktop, tablet, mobile }` when the source tightens below desktop
  (`getComputedStyle` per viewport makes this free);
- emits **`'0'`** for a band measured flush. This matters: an absent value leaves
  the template's own rhythm in place, which is how the hero band (`no-padding-tb`
  in the source) rendered with 32px of padding the source never had. "Measured
  zero" and "never measured" are different facts.
- an explicit `section.padding` in the profile always wins.

### `minHeight` — per device, a floor

Same shape, from `BandGeometry.height`, gated by
`MIN_MIRRORED_BAND_HEIGHT = 120` (a 100px logo rail is content-driven; pinning it
fights the rebuild's own type scale).

It is applied as `min-height` — a **floor, never a lock**. A band whose rebuilt
content is already taller keeps its own height, so the measurement can never clip
content. Consequence to state plainly in reports: a floor closes *undershoot*
(hero 746 → 900) and cannot close *overshoot* (features renders 1439 against a
1339 source — a content-scale difference, not a box difference).

### `measure` and `paddingX` — per device, and neither is a constant

```ts
function measuredContainer( bandFacts ): { measure?, paddingX? }
```

`ForgeBandFacts.container` (`analyze --geometry`) records, per device, the
source's inner container: its CONTENT width, its own side padding (`padX`), and
whether the band's grid CANCELS that padding (`flush`).

- **`measure` is the BORDER-BOX width** (`content + 2 * padX`), not the content
  width. The rebuild's `max-width` includes the padding, so emitting the content
  width alone renders the band one gutter too narrow.
- **`paddingX` is `0` when `flush`, otherwise `padX`.** A Bootstrap-family `.row`
  carries a NEGATIVE horizontal margin that pulls its columns back out over the
  container's padding — the same pairing that made `.container-fluid` padding and
  `.row` margin inseparable. The rebuild's stylesheet never defines `.row`, so
  the section must render flush and let the per-column padding (already in the
  band's `box` facts) provide the visible gutter. Measured, not assumed: the sign
  of the first child's `margin-left` decides.
- **Both are emitted PER DEVICE.** They used to be one desktop value plus the
  constants `BAND_GUTTER_PX = 64` and `FLUID_CONTAINER_MIN = 1300`, which
  compensated for a 32px desktop gutter at a viewport where the template's own
  gutter is 16. Both constants now survive ONLY as the fallback for a profile
  analysed before the fact existed, so an un-re-analysed profile scaffolds
  unchanged.
- A per-device map goes to scoped CSS (`responsiveBandCss`), never inline: an
  inline value outranks its own media queries. Verified in the built HTML —
  `[data-section-id="…"]{--ds-measure:1440px}` then
  `@media (max-width:1024px){…{--ds-measure:834px}}`.

Why it mattered (virtuallyjamaica): at 834px a boxed container measures 720px of
content with a 15px gutter, so its columns span 750px — the old derivation gave
770px, 20px wide, and text rewrapped (the sub-1% mobile height warnings). At
390px a BOXED band rendered 358px against a source 390px.

Verified result: `verify --geometry` went from **0 fail / 2 warn** to **0 fail /
0 warn** ("every band matches the source box at every viewport"). The pixel gate
is UNCHANGED by this (11.278% before and after) — the gutter was the geometry
blocker, not the pixel one, whose causes are the hero offset, the container's
horizontal placement and an image rendition.

## 5. Renderer: two bugs worth remembering

`DesignRenderer.astro` `wrapStyle()`:

- **`min-height` must be applied BEFORE the hero early return.** It was first
  added next to `zIndex`, which sits after `if ( theme.theme === 'hero' )` — so
  the hero band, precisely the one a source height pins (virtuallyjamaica's
  full-screen contact band), silently never got it. Symptom: contact 675 vs 900
  while every other band matched.
- **`--ds-split-ratio` is a TWO-lane contract.** It was emitted for any
  `columns.length >= 2` using only the first two widths, so a three-lane band
  (services, 25/33/42) pushed a truncated `0.25fr 0.3333fr` into a two-lane grid.
  Guard is now `columns.length === 2`.

`responsiveBandCss` keys on `section.id ?? theme.id`. Outline-band sources carry
no section id of their own, so keying on `section.id` alone emitted **no rule at
all** and every per-device padding/min-height fell back to template defaults.
This is the same class of bug as `bandSource`: the wrap renders
`data-section-id={section.id ?? theme.id}`, so the scoped CSS must use the same
expression.

## 6. Serving an SSR build to the gates

`verify --geometry` / `--colours` / `--pixel` load the build over HTTP
(`file://` breaks absolute assets). `serveDir()` maps `/` → `<root>/index.html`,
but an Astro **server** output writes the browser tree to `dist/client`, so
serving `dist` 404s at `/` and every band measures as missing.

`builtStaticRoot(distDir)` returns `dist/client` when it carries an
`index.html`, else `dist`. Symptom without it: `✗ Geometry: band … build missing`
on every band at every viewport, which reads like a catastrophic rebuild
regression rather than a serving bug.

## 7. The builder layout shim is a 12-column GRID

`templates/blog/src/styles/global.css`. Rows were `display: flex` with
`flex: 1 1 0` per column, which forces **equal** lanes and never reproduces the
source's wrapping.

Two failures and their fixes:

| Symptom | Cause | Fix |
|---|---|---|
| services 25/33/42 rendered as three equal thirds | equal-share flex | `grid-template-columns: repeat(12, minmax(0,1fr))` + `grid-column: span N` |
| services 872 at tablet vs source 1445 | source is 6/6/12 below desktop, so the third column wraps to a second row; a flex row kept all three on one lane | grid wraps per span, like the source |

Details that matter:

- `[class~='vc_col-lg-3']` is a **whole-token** match. `[class*='vc_col-lg-3']`
  would also select `vc_col-lg-30`-style collisions (`-3` inside `-30`); the
  substring form matches `vc_col-lg-1` against `vc_col-lg-10/-11/-12`.
- Express spans as `grid-column`, not `flex-basis`. A percentage basis plus the
  row `gap` overflows (25% + 33% + 42% + two gutters > 100%) and wraps
  arbitrarily.
- Non-column children of a row need `grid-column: 1 / -1`, or a plain `<p>` in a
  row occupies one twelfth of the width.
- Mobile: `grid-column: span 12` (the source's `vc_col-sm-12` /
  `col-xs-mobile-fullwidth`).
- **Carousels are one lane: `flex-wrap: nowrap` + `flex: 1 1 0`.** A carousel
  left as a wrapping flex row with `flex-basis: 8rem` overflowed at seven logos
  (7×128 + 6×36 > the 1088px lane) and dropped the last onto a second row —
  doubling the band height (817 vs 649). The source carousel scrolls; the rebuild
  shares the lane.
- Split `.owl-carousel` / `.owl-stage` out of the row selector list — they were
  in the same `:is()` and inherited the grid.

## 8. Verified result on virtuallyjamaica

`verify --geometry --bands`, 1440/834/390:

- 56 → 23 → 17 → **2** geometry failures across the three iterations.
- All padding, width and per-viewport height failures closed; 5 of 7 bands match
  at all three viewports.
- `40f5e88` hero, `1d5804b` about, `1ff4891` wifi, `499464d` services,
  `cd02263` hardware, `eef1693` contact — heights within tolerance.
- `860a86e` features remains: +100 desktop (1439 vs 1339) and +546 mobile. This
  is overshoot, which a `min-height` floor cannot correct. It is a content-scale
  difference inside a raw band.

**2026-11-11:** the two surviving sub-1% mobile warnings (`#features` +15px,
`#about` +16px) closed with the per-device container measure in §4. `verify
--geometry` now reports **0 fail / 0 warn** — every band matches the source box at
every viewport. The pixel gate is unaffected (see §4).

## 9. Known gap: column inner padding (not yet implemented)

Columns render flush to their edges because the source's inner padding is
class-based and no vendor CSS ships. Measured evidence (fetched from the source's
own `style.css?ver=7.1.4`, so this is a fact, not an inference):

```
.padding-one-all{padding:1% !important}   … .padding-eighteen-all{padding:18%}
.padding-N-top|bottom|left|right|lr|tb    same N%
.no-padding-{tb,lr,all,…}                 zero
responsive.css: .md-padding-N-*  /  .sm-padding-N-*  /  .xs-padding-N-*
```

So the convention is **percentage**, `one…eighteen` → 1…18%. `padding-ten-lr` on
the hardware band measured 144px L/R at a 1440 viewport — exactly 10% — which
corroborates the mapping.

Implementation caution: the band's own class padding is ALREADY reproduced on
`.ds-section-wrap` by the measured `padding` fact, so a naive global application
of these utility classes double-pads every band. Any future rule must exclude the
band element itself (e.g. scope to columns, or `:not([class*='brando_row'])`), and
must be re-verified with `verify --geometry` because added inner padding can
convert an undershoot into an overshoot.

## 10. Commands

```bash
# record / refresh the source baseline (does NOT need --design when the
# profile already carries a design block)
node scripts/forge.mjs analyze https://virtuallyjamaica.com \
  --geometry --write sites/virtuallyjamaica.json

# diff the build against it
node scripts/forge.mjs verify sites/virtuallyjamaica.json \
  --target virtuallyjamaica-local --geometry --geometry-limit 40
```

`--geometry` needs Playwright (`npm i -D playwright`, `npx playwright install
chromium`). It is opt-in on the CLI and skipped with a warning — never a pass —
when the browser or the baseline is absent.
