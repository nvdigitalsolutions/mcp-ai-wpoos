# Measured band facts and the sticky header (2026-11-10)

Two measurement passes that closed the "the sections don't match the live
version" gap on virtuallyjamaica (WPBakery + Brando, rebuilt from its own
sanitized markup). Both ride `analyze --geometry`, both land as top-level
profile keys (like `geometry`), both are browser facts rather than stylesheet
scavenging.

## 1. Band facts — the raw-HTML path has no typography of its own

Every band the classifier cannot give a structural kind renders through the
raw path (`<div class="post-body ds-section">{html}</div>`). That path had no
heading scale at all, so the browser's UA defaults applied **on top of the
13px rebuilt body**:

| source | rendered | why |
|---|---|---|
| `h1` 55px | 26px | `2em` × 13px |
| `h2` 28px | 19.5px | `1.5em` × 13px |
| `h3` 40px | 15.21px | `1.17em` × 13px |
| `h6` 13px eyebrow | 8.71px | `0.67em` × 13px |

Every band's hierarchy collapsed while the box-geometry gate passed (the boxes
were right; the type inside them was not). `analyze --geometry` now measures,
per band, the **computed** style of each heading level present plus the body
paragraph (`lib/geometry.ts` → `measureBandFacts`, stored as
`profile.bandFacts[id]`):

- `headings[h1..h6]` — size / weight / line-height / letter-spacing /
  text-transform / **text-align**, taken from the LARGEST rendered instance of
  that level;
- `body` — the MOST COMMON paragraph size (an intro can be set larger than the
  body copy);
- `containerWidth` — the band's inner container content width (`.container`
  boxes it at 1140px, `.container-fluid` is the viewport). The scaffold adds the
  template's two 32px gutters back (`BAND_GUTTER_PX`) and emits it as the band's
  `measure`, which is what makes virtuallyjamaica's fluid services band render
  1440px instead of the site measure's 1136px.

`DesignRenderer` emits `[data-section-id="X"] h3{…}` / `… p{…}` into the same
scoped `<style>` block as the responsive padding, and `--ds-title-size` /
`--ds-title-weight` / `--ds-title-lh` / `--ds-title-ls` / `--ds-title-align` on
the wrap (the form band's title is a `ds-title`, not an `<h3>`).

Rules that cost a fix:

- **A scoped rule written before the band still has to satisfy the fact
  audit.** `lib/fact-audit.ts` slices a band's own HTML, so a `[data-section-id]
  h3{…}` rule in a `<style>` block never appears in the slice — the `type`
  marker therefore uses a `present` predicate that regexes the whole page.
- **`text-align` is inherited** — reading `getComputedStyle(p).textAlign` is
  how a centred band (the source centres from a wrapper class the sanitizer
  strips) is detected. Emit only `center`/`right`/`justify`; `start`/`left` are
  the template default.
- **`analyze --write` must not force `--design`** for this (it would overwrite
  hand-tuned `design.chrome`/`tokens`). `mergeProfileDraft` carries `bandFacts`
  and `stickyHeader` the way it carries `geometry`.

## 2. The sticky header — measured, including its scrolled state

`analyzeChrome`/`analyzeScrollBehavior` could say "the header is sticky", but
not what it LOOKS like once the page moves: the theme adds the class from
JavaScript (`shrink`, `is-sticky`, …) and the resting rule is usually
transparent. `lib/geometry.ts` → `measureStickyHeader` scrolls a real page and
re-reads the computed style:

```
rest:   position fixed|sticky (or a sticky-ish class appears on scroll)
        → bg transparent, 75px
pinned: → bg rgba(0,0,0,0.7), 70px
```

stored as `profile.stickyHeader` (`sticky`, `restBg`, `restInk`, `restHeight`,
`pinnedBg`, `pinnedInk`, `pinnedHeight`, `shadow`, `bgChanged`,
`heightChanged`). The scaffold emits it into `behaviors.generated.ts`
(`headerSticky` is true from either the static pass or the measurement,
plus `headerPinnedBg|Ink|Shadow`, `headerRestHeight`, `headerPinnedHeight`), and
`Base.astro` turns them into `--forge-header-pinned-*` / `--forge-header-rest-h`
vars on `<body>` plus a `site-header--pinned` class.

The rebuild is **CSS-only** — a `scroll(root)` timeline, no scroll listener:

```css
.site-header--pinned { animation: ds-header-pin linear both;
                       animation-timeline: scroll(root); animation-range: 0 120px; }
@keyframes ds-header-pin {
  from { background-color: var(--forge-header-bg, transparent); color: …;
         min-height: var(--forge-header-rest-h, auto); }
  to   { background-color: var(--forge-header-pinned-bg, …); color: …;
         box-shadow: var(--forge-header-pinned-shadow, none);
         min-height: var(--forge-header-pinned-h, …); }
}
```

Rules that cost a fix:

- **The resting state is the base here, deliberately.** The golden rule wants
  the finished state as the default, but a header's finished state is the
  SCROLLED one — painting a solid pinned strip over the hero at scrollY 0 is the
  louder error.
- **The browser lags the scroll position.** On the live Virtually Jamaica page
  the pinned colours only appear a beat after the class lands (a transition plus
  a debounced scroll handler). Read the pinned snapshot after a real wait
  (900ms) and kill transitions/animations first, or you record `transparent`
  for a header that is plainly dark on screen.
- **Verify with the same discipline:** map the rebuilt header across scroll
  positions (0 / 100 / 200 / 600 …) and read `backgroundColor` + `animationRange`
  — a first read taken too soon after `scrollTo` reports the previous frame and
  looks like the animation never fires.
- `behaviors.generated.ts` and the template stub both carry the optional keys;
  `astro check` fails on a project whose stub predates them.

## 3. Hidden-by-source subtrees — measured, not hard-coded

A builder hides its accordion panels, tab panes, mega-menus and mobile drawers
with a class the sanitizer keeps and the rebuilt sheet never reaches, so every
panel rendered OPEN: virtuallyjamaica's features band carried 2352 characters
against the source's 486. The first fix was a hand-written
`.panel-collapse.collapse:not(.in) { display:none }` in `global.css` — correct,
and useless on the next builder.

`measureBandFacts` now collects, per band and at BOTH breakpoints, the class
signature (sorted class tokens) of every element whose computed `display` is
`none`, plus its visible siblings. `signaturesHiddenAtBoth` keeps only the
signatures hidden at both viewports and turns each into a selector:

- no visible sibling shares the classes → `[class~="a"][class~="b"]`
- a visible sibling adds one or two classes (the accordion's `.in`) →
  `[class~="a"][class~="b"]:not([class~="in"])`
- hidden at one breakpoint only, or appearing both hidden AND visible in the
  same band → skipped (a responsive state is not a resting state)

Scaffold → `sections.generated.ts` `hidden: string[]` → the renderer emits
`[data-section-id="X"] <selector>{display:none}`. Measured on
virtuallyjamaica: the accordion panel (`:not(.in)`), the disabled owl-nav
arrows, and three CF7 wrappers. The hand-written rule was then removed from
`global.css`; the mechanism replaced it.

A closed panel has no CSS-only way back open (the source toggled it with JS).
The closed state is the faithful RESTING state, which is what a rebuild owes —
but a widget whose only control does nothing is not a rebuild either. The
missing half is behaviour, and `src/components/BuilderAccordion.astro` supplies
it as progressive enhancement: on any `.post-body .panel-group` it toggles the
panel's `.in` class (the class the measured selector tests), closes siblings
(the source's `data-parent` contract), mirrors `aria-expanded`/`aria-controls`
and `role=button`, handles Space, opens a `#hash` target, and keeps the source's
`active-accordion` class in sync. It marks the group `data-accordion`, so
`global.css` can hide a closed panel even when the source rested "all open" and
no measured selector exists. No JS → the resting state, unchanged.

`global.css` also draws the `+`/`−` marker: the source's glyphs are `fa-plus` /
`fa-minus` from a Font Awesome sheet a forged site does not ship, so the control
had no affordance at all. It is absolutely positioned (the source's own
`span.pull-right` is), or the marker steals title width and wraps headings the
source kept on one line. The accordion TITLE colour is declared in the same
block at vendor-beating specificity: `.accordion-style1 a{color:#ababab}` from
the source sheet otherwise overrides the rebuild's accent, and the source's
per-element inline colour does not survive sanitisation.

## 4. The class-coverage audit — the inventory behind all of the above

Every hand fix in this arc shared one shape: a class the sanitizer kept that no
stylesheet reached. `lib/class-audit.ts` (run by `verify`, report-only) answers
it once:

```
⚠ Class coverage: 119 of 196 class name(s) match no stylesheet rule (77 resolved).
  brando-column-container ×16 (45f5e88, 1d5804b, …)
  col-xs-mobile-fullwidth ×16 (45f5e88, 1d5804b, …)
  no-margin ×9 (860a86e, 499464d)
  …
```

Written to `<target>/verify-artifacts/class-coverage.json`. `--no-classes`
skips it, `--strict-classes` turns it into a gate (off by default: a class is
not REQUIRED to be styled, and many inert ones — `attachment-full`,
`wp-image-…` — are inert by design).

Rules that cost a fix:

- **Attribute selectors count as coverage.** The shim styles families with
  `[class*="brando_image_"]` and `[class~="vc_col-lg-6"]`; a naive `.name` scan
  reports those as missing and the audit becomes noise. `=`, `~=`, `*=`, `^=`,
  `$=`, `|=` are all honoured (`~=` is a whole-token match, so `vc_col-lg-6`
  does not cover `vc_col-lg-66`).
- **The page's inline `<style>` blocks are part of the sample.** The per-band
  rules (typography, hidden subtrees, responsive padding) are emitted inline,
  not in `_astro/*.css`; auditing only the CSS files reported every band rule
  as missing.
- **Scan PRELUDES, not the whole sheet.** A `{` inside a quoted string or an
  attribute selector must not end a prelude, and stripping quoted strings (the
  obvious shortcut) destroys `[class~="x"]`. The scanner tracks quotes and
  resets the buffer on `{`/`}`.
- Deterministic output: most-used first, then alphabetical, deduped by band.

## 5. The builder style pack — vendor utilities as DATA

The utilities that closed the last band of gaps (`text-center`,
`text-uppercase`, `text-medium`, `padding-four-all`, `font-weight-600`,
`pull-right`, …) were first written into `global.css`. That worked and was
wrong: the next Brando site needs them, an Elementor site must not inherit them,
and a Divi site has its own conventions.

They now live on the adapter (`BUILDER_ADAPTERS[].utilities`, class →
`{ base: 'declarations' }`) and are emitted per band, **trimmed to the classes
the page actually uses**:

```ts
// lib/bands.ts — wpbakery, values measured on the live source
'text-medium': { base: 'font-size:14px;font-weight:500;line-height:24px' },
'padding-four-all': { base: 'padding:3.4rem' },
```

`analyze --geometry` records each band's class inventory (`bandFacts[].classes`,
the union of both viewports); `builderUtilitiesFor(builder, classes)` returns
the intersection; the scaffold writes it to `sections.generated.ts
`utilities: Record<string,string>``; the renderer emits
`[data-section-id="X"] .text-center{text-align:center}`.

virtuallyjamaica's wifi band emits 8 utilities, its services band 3 — not the
whole vendor dictionary. `global.css` no longer declares any of them.

Rules that cost a fix:

- **Values must be measured, never guessed.** The numeric spacing scale
  (`margin-four-tb`, `padding-two-all`, `padding-five-lr`) is deliberately NOT
  in the pack: Brando's numbers are not a fixed px/rem ladder (`margin-four-
  bottom` is 7.8px on a 50px icon and 21.6px on a body paragraph). They stay in
  the audit list until a measurement exists.
- **Responsive variants ARE in the pack** (added 2026-11-11): `md-text-center`,
  `xs-text-center`, `sm-text-center`, `xs-padding-40px-tb`, `sm-padding-70px-tb`
  map to the template's tablet/mobile ranges. Only variants whose NAME states the
  value are mapped (`xs-padding-40px-tb`); a numeric-scale variant stays out.
  Where the pack and a MEASURED box fact disagree, the measurement wins — see §6.
- **`padding-four-all` is no longer `padding:3.4rem`.** Measured at 390px it is
  `40px 15.5938px`; the pack still carries the desktop value, and the measured
  box fact overrides it per device (§6).
- **A profile recorded before `bandFacts[].classes` exists emits nothing** (no
  classes → no intersection). Re-run `analyze --geometry --write` before
  scaffolding; the audit reports whatever is still unstyled.
- Utility rules are scoped `[data-section-id] .cls` (specificity 0,2,0), so they
  win over the band's own `[data-section-id] p` body rule — correct: the source's
  `text-medium` label is 14px while the band body is 13px.

## 6. Measured box facts — the builder's numeric scale, resolved by the browser

The last two `verify --geometry` failures were both the *same* class of miss:
a builder's spacing scale cannot be transcribed from class names. Measured on
virtuallyjamaica:

| class | desktop | tablet (834) | mobile (390) |
|---|---|---|---|
| `padding-four-all` | 57.59px | 70px / 33.36px | **40px / 15.59px** |
| `margin-four-bottom` | 7.8px on an icon | — | 21.6px on a paragraph |
| `padding-six-lr` | 32.39px | 41.39px | 19.8px |

So the utilities pack (§5) is an approximation and can never be more. The fix is
to MEASURE the computed box, per class, per device.

`collectBandBoxFacts` (module scope in `lib/geometry.ts`, run beside
`collectBandTypeFacts` at each of the three viewports) walks the band and records,
for every class the band keeps, the computed `padding` and `margin` shorthands in
px — **plus the rendered height of any `img` whose rendered aspect disagrees with
the file's** (the source crops it; virtuallyjamaica's features image is `330×435`
from a `390×584` file, so `height:435px` is a real fact).

`mergeBoxFacts` keeps the desktop value as `base` and records a device only where
it differs (same discipline as `attachDeviceOverrides`). The scaffold writes
`box: Record<class, { base?, tablet?, mobile? }>` into `sections.generated.ts`;
`bandBoxCss` in `DesignRenderer.astro` emits it **after** `bandUtilityCss`, so a
measured value always outranks a hand-authored approximation of the same class.

Rules that cost a fix, in the order they cost it:

- **Units are mandatory.** The first pass emitted `padding:57.59 57.59 57.59
  57.59` — an invalid declaration the browser drops silently, so nothing applied
  and the failure looked unchanged. `boxDeclarations` now always writes `px`.
- **Zero is a fact, not noise.** Skipping all-zero boxes left the source's
  `margin: 0` on a heading unstated, so the template's prose default (17.29px)
  leaked in. Only an INCONSISTENT class is dropped.
- **A device reading must not leak below its range.** `@media (max-width:1024px)`
  also matches 390px, so a tablet `margin: 0 42px` on `.container` carried into a
  phone and shrank the built column to 274px against the source's 360. Box facts
  emit the tablet range as `(min-width:768px) and (max-width:1024px)`
  (EXCLUSIVE) — unlike the utility pack, which cascades on purpose.
- **Never restate half of the Bootstrap grid.** `.container-fluid` carries 15px of
  side padding that `.row`'s `-15px` margin cancels. Re-stating the padding
  without the margin shrank `#wifi` to 1376px against 1440 and grew it 52px tall.
  `SCAFFOLDING` (`container`, `container-fluid`, `row`, `vc_row`, `wpb_row`) is
  excluded from measurement: the template owns the outer grid, the measured sheet
  owns the COLUMN and module boxes, which is where the source's layout lives.
- **Only a class with a single consistent value is recorded.** Brando's
  per-column hashes (`brando_column_<id>`) appear once, so the column padding the
  rebuild otherwise loses is always captured. A shared class used at two sizes
  (`panel-body` open vs closed) gets no rule rather than an arbitrary one.

## 7. The header is a LAYOUT fact, not only a colour

Measuring the scrolled colours was necessary and not sufficient. Three things were
still wrong, and all three came from the header's own computed presentation:

1. **`position: fixed`.** The source header floats OVER the first band; a sticky
   rebuild holds a row in the flow and pushes that band down a full header
   height. On virtuallyjamaica that left the WHITE logo and WHITE nav links sitting
   on the white page above the red hero — invisible. `measureStickyHeader` now
   records `position`, and `site-header--float` reproduces it.
2. **The resting background is transparent, and `chrome.headerBg` was `#dd3333`.**
   Brando declares a red `.navbar` background that a more specific transparent
   variation overrides, so the stylesheet harvest picked a strip the source never
   paints. `applyMeasuredHeader` reconciles the harvest with the measurement: no
   `restBg` → clear `headerBg`, set `chrome.headerTransparent`, and the header
   paints nothing (`site-header--overlay`).
3. **The container ink and the nav-link ink are different colours.** The source
   keeps the nav element at the body ink (`#626262`) while its links paint white.
   `chrome.headerInk` is now the measured container ink; `--forge-header-nav-ink`
   carries the link colour separately.

The height animation also had to change: `min-height` cannot shrink a 79px header
to a 70px floor. `site-header--sized` sets a definite `height` from
`--forge-header-rest-h`, and the keyframes animate `height`.

Result — built and source agree exactly at both states:

| | background | height | ink |
|---|---|---|---|
| rest | `rgba(0,0,0,0)` | 75px | `rgb(98,98,98)` |
| pinned | `rgba(0,0,0,0.7)` | 70px | `rgb(98,98,98)` |

## 8. Two raw-HTML fidelity bugs this uncovered

- **Heading classes were stripped by the sanitizer.** `allowedAttributes` listed
  `class` for `div`, `span`, `a`, `i`, `ul`, … but not for `h1`–`h6`, so the
  accordion's `panel-title` heading lost both its class and its measured box: the
  rebuilt title rendered at the template's 17px prose margin and without the
  source's 40px icon gutter. Headings now keep `class`. This alone is most of the
  class-coverage drop from 109 inert to 19.
- **A fluid band was inset by the template gutter.** `.ds-section` pads
  `clamp(1rem, 4vw, 2rem)` per side; a `.container-fluid` source band spans the
  viewport. The scaffold now derives `paddingX: '0px'` when the measured container
  is fluid (`containerWidth >= FLUID_CONTAINER_MIN` = 1300 — 1410px of a 1440px
  viewport; a boxed `.container` measures 1140px and keeps the inset).

## 9. Where virtuallyjamaica landed

| gate | before | after |
|---|---|---|
| box geometry | 2 fail (mobile) | **0 fail, 2 warn** (features +15px, about +16px) |
| colour system | 4 drift (header bg/text, band:home, footer) | **0 drift** |
| class coverage | 109 inert of 196 | **19 inert of 219** |

- `services` mobile height is now **1281px — exact** against the source.
- `features` mobile went 1722 → 1540 against 1525 (−1%).
- `#wifi` desktop went 1128 → 1076 — exact — once the fluid inset was removed.

The two remaining mobile warnings are sub-1% and inside 2× the 10px tolerance.

The last colour drift (`band:home text`) was a scoping bug, not a colour choice:
`.ds-section-wrap--dark` set the light ink on the WRAP, while the source leaves the
band element at the body ink and lets the content layer carry the light foreground.
The rule now colours the wrap's children (`--hero` already worked that way), so the
band element matches the source and every descendant resolves the same colour.

## 10. Re-running this

```bash
node scripts/forge.mjs analyze https://virtuallyjamaica.com --geometry --write sites/virtuallyjamaica.json
node scripts/forge.mjs sites/virtuallyjamaica.json --target ./virtuallyjamaica-local --overwrite
cd virtuallyjamaica-local && npm install && npm run build && cd ..
node scripts/forge.mjs verify sites/virtuallyjamaica.json --target ./virtuallyjamaica-local --geometry
```

Two Windows notes: `--overwrite` replaces the target directory, so `node_modules`
goes with it (move it aside and back to skip a 60s install); and scaffolding a
live target without `--overwrite` hits the documented `EPERM` rename race on the
`.forge-tmp-*` directory.
