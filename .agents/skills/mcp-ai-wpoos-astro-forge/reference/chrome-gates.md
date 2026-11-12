# Chrome gates — ids, resolution, and the opened mobile menu

Three gates added after a round where every failure was a **coverage** bug: the
gate measured something, just not the thing that was broken
(2026-11-12, virtuallyjamaica + jayainc).

## 1. `built ids` — duplicate ids in the built page

Runs whenever the built HTML exists; no flag, no browser.

The band wrapper emitted an anchor id the band's own markup already owned
(`id="wifi"` on Brando's half-column), so the page carried it twice.
`querySelector('#wifi')` resolved to the wrapper: in-page links landed on the
band instead of the red panel, and `verify --colors` compared the band's
`#ededed` against the source's `#dd3333` panel — a colour "drift" that did not
exist. The wrapper now yields (`templates/blog/src/lib/anchor.ts`); this gate
proves it on the output.

- Duplicates are invalid HTML everywhere; they **break** the document where
  something links to them. So: a duplicated id that is an in-page target
  (`href="#x"`, `/#x`, `…/#x`, `index.html#x`) **fails**; a stray duplicate
  nobody links to warns.
- `lib/html-ids.ts` reads start tags only, strips comments/`<script>`/`<style>`
  first, and ignores ids written in prose. It is not a validator.
- Homepage only: that is where the one-pager anchors live.

## 2. Colour-region resolution — "this gate has no opinion"

`measureRegionColorsDetailed` (the engine behind `analyze --colors`,
`colors`, `verify --colors`) now reports, per region, the selector that matched,
the element's box and how many text-bearing elements carried ink.

`unresolvedChromeRegions` turns that into findings:

| reason | meaning |
|---|---|
| `missing` | no selector in the ladder matched |
| `empty` | matched an element with no box (0 height/width) |
| `no-ink` | matched an element with no text |

jayainc's `footer` ladder matched WordPress's empty `footer.entry-meta` because
the bare tag came first: found, boxed, no text → every metric on it `skipped`,
which read as a pass. Now `analyze --colors` warns at record time and
`verify --colors` warns at gate time (`--strict-colors` fails). `no-ink` is a
warning only: an icon-only header has no text either, and an operator can tell
the difference.

## 3. `verify --chrome` — the OPENED mobile menu

One extra browser pass at 390×844; needs `analyze --geometry` (the source's
panel is `chrome.mobileNav`).

The colour gate measures 1440px at rest, so it cannot see the piece of chrome a
phone reader meets first. The rebuilt panel had no surface at all: white links
on the photo hero, unreadable.

`measureMobileNav` is url-only, so the gate measures the served build with the
same function that recorded the source and diffs the two (`compareMobileNav`):

| fact | grading |
|---|---|
| `background` absent in the build | **fail** — the links paint on the band behind the header |
| `background` ΔE00 | fail ≥ 6, warn ≥ 3; gradients compared as values |
| `ink` ΔE00 | fail ≥ 6, warn ≥ 3, only when both sides have a surface |
| `overlay` | warn (overlay ↔ in-flow changes what the menu covers) |
| `padX` / `itemPitch` | warn beyond 4px |
| no disclosure in the build | **fail** — the source opens one at this width |

Contrast alone would NOT have caught it: white on virtuallyjamaica's `#dd3333`
hero is **4.57:1**, which passes AA for normal text. The defect is "there is no
panel", not "the ink is low-contrast" — so the gate asserts the surface exists
and matches, then compares the ink.

Verified in both directions on the real build: clearing
`--forge-mobile-nav-bg` produces

```
✗ Mobile menu: mobileNav.background — source rgba(0, 0, 0, 0.95), build transparent
  — the opened menu has no surface — its links paint onto whatever band is behind the header
```

and restoring it passes with 4 compared metrics.

## 4. `restHeight` is recorded even when the header shrinks

`measureStickyHeader` used to omit the resting height whenever a pinned strip
existed or the header shrank (`headerShrank || stripInfo ? {} : { restHeight }`),
so `--forge-header-rest-h` was never emitted and the rebuild sized the header
from its content: **47px against the source's 75px**. The panel then started
28px too high and the hero copy showed through the 95%-black sheet.

It is now always recorded for a sticky header. Scope: the pass measures at
1440px, so the fact assumes the resting height does not change per device —
true for virtuallyjamaica (75/75/75), and non-sticky headers (jayainc: 100/160/100)
never reach this branch. A header that changes height per device is covered by
the header-box pass (section 5) for its BOX, not for its height.

## 5. The header BOX — `analyze --geometry` → `chrome.geometry` → `verify --chrome`

The reported drift was *"the header still looks wrong with the logo on the left,
it should be boxed and not full width"*. It is invisible at 1440 on jayainc: the
source's container is `1670px` wide with `35px` gutters, so at 1440 it spans
the viewport. At 1920 the source keeps its 1670px box (brand at x=160) while a
full-width rebuild pins the logo to the edge (x=35) and stretches the bar.

`measureChromeGeometry` (lib/geometry.ts) measures at **1920 / 1440 / 834 / 390**
and records, per viewport:

| fact | meaning |
|---|---|
| `measure` | the container's cap, only where it is **binding** (box narrower than the header) |
| `padX` | the container's computed `padding-left` — the gutter inside the box |

Two container shapes are recognised:

- a declared cap — jayainc's `.aux-container { max-width:1670px; padding:0 35px;
  margin:auto }`, read from computed `max-width` (stable across viewports);
- a per-breakpoint width — virtuallyjamaica's Bootstrap `.container`
  (1170 at 1440, 970 at 1024, 100% below 992) with 15px padding, read from the
  measured box.

The container is chosen as the **outermost padded ancestor** wide enough to be
the row (≥60% of the header). Walking up rather than down matters: a Bootstrap
row and its `.container` share a width, and the row is the one without the
gutter; walking down would also latch onto the logo COLUMN (virtuallyjamaica's
785px `.col-lg-2` at 834), which is padded but is not the header's box.

Grading (`compareChromeGeometry`):

| fact | grading |
|---|---|
| source has `measure`, build reports none | **fail** — the build runs full-bleed and pins the logo to the edge |
| `measure` Δ > 4px | warn — the cap is a design decision, not a pixel contract |
| source has `padX`, build none | warn — the brand sits on the container edge |
| `padX` Δ > 4px | warn |

Rendering: the scaffold emits `geometry` into `chrome.generated.ts`; Base.astro
builds one inline rule from the widest measurement plus per-breakpoint
gutters, at `.site-header > .site-header-inner, .site-topbar > .site-topbar-inner`
specificity so it wins over the template default regardless of stylesheet
order. The header/topbar keep the full-bleed SURFACE; only the ROW is boxed
(jayainc: 1670px, 35px gutters; virtuallyjamaica: 1170px, 15px). The topbar is
included because the source aligns its social glyph and contact group to the
same container.

Both pilots verify clean: jayainc 5 metrics (wide cap + four gutters),
virtuallyjamaica 6 metrics.

## 6. Pseudo surfaces (P5) and ancestor gradients (P6)

`readBackground` (lib/region-colors.ts) now resolves two layers it used to skip:

- **P5 — `::before`/`::after` paint.** A builder paints many bands on a pseudo
  layer (a hero's overlay, a tinted `::after`). Computed styles do not chain
  through pseudos, so the band read as transparent and the gate fell through to
  the page canvas. Only **positioned** pseudos qualify — a static pseudo is an
  in-flow decoration (a 125×6 divider bar), and calling that the band surface
  would compare a rule against a full-height band. The pseudo is read after the
  element's own opaque colour/image (an overlay paints ABOVE it) and before the
  descendant scan and ancestor walk; it applies to plain chrome regions too
  (header/footer), not just `descendantFill` bands.
- **P6 — gradient ancestors.** An inner-section anchor (`#products` is an
  Elementor `inner-section`) has no surface of its own; the ancestor band does,
  and that surface is a gradient as often as a colour. The ancestor walk accepted
  only `background-color` and stepped past the gradient to the page canvas.
  It now accepts an opaque `background-image` (or a positioned pseudo) under the
  same 2× area guard.

Covered by `tests/region-surfaces-browser.test.ts` (hermetic fixture: inner
section inside a gradient band, positioned `::before` overlay, static pseudo
ignored, overlay above an own colour).

## Roadmap (remaining)

- **Header height/alignment per device** — the box gate covers width and
  gutter; the 160px two-row tablet header (jayainc centres its brand at
  834/1024) is a layout variant the rebuild does not ship yet.
